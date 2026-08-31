<?php
defined('ABSPATH') || exit;

/**
 * Ties AVBK_Xlsx_Reader + AVBK_Matcher + AVBK_DB together: reads an
 * uploaded export, dedupes against previous imports, and applies every
 * confident match immediately (IBAN or reference code) — only rows nobody
 * can be confident about land in the review queue.
 */
class AVBK_Import {

    /** @return array{batch_id:int, row_count:int, matched_count:int} */
    public static function process_file(string $path, string $filename, int $uploaded_by): array {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $layout = AVBK_Bank_Import_Layout::resolve();
        $parsed = $ext === 'csv'
            ? AVBK_Csv_Reader::read($path, $layout['csv_delimiter'])
            : AVBK_Xlsx_Reader::read($path);
        $transactions = [];
        foreach ($parsed['rows'] as $raw_row) {
            $transaction = AVBK_Matcher::parse_row($raw_row, $layout);
            if ($transaction) {
                $transactions[] = $transaction;
            }
        }
        if ($parsed['rows'] && !$transactions) {
            throw new \RuntimeException('Geen transactierijen herkend. Controleer het gekozen importprofiel, de kolomnamen en het datumformaat.');
        }
        $batch_id = AVBK_DB::create_import_batch($filename, $uploaded_by);

        $row_count = 0;
        $matched_count = 0;

        foreach ($transactions as $tx) {

            $hash = AVBK_DB::dedupe_hash(
                $tx['transaction_date'],
                $tx['amount'],
                $tx['counterparty_iban'],
                $tx['description'],
                $tx['counterparty_name'],
                $tx['direction']
            );
            if (AVBK_DB::transaction_exists($hash) || AVBK_DB::find_semantic_duplicate($tx)) {
                continue; // already imported in a previous upload — safe no-op
            }
            $row_count++;
            $tx['import_batch_id'] = $batch_id;
            $tx['dedupe_hash'] = $hash;

            if ($tx['direction'] !== 'in') {
                // Outgoing (expenses etc.) — kept for the record, never matched.
                $tx['status'] = 'ignored';
                $tx['ignore_reason'] = 'import_outgoing';
                AVBK_DB::insert_transaction($tx);
                continue;
            }

            $type_hints = AVBK_Matcher::classify_types($tx['description']);

            $exact_fee_items = self::resolve_exact_fee_reference(
                AVBK_Matcher::match_fee_item_reference($tx['description']),
                (float) $tx['amount']
            );
            if ($exact_fee_items) {
                $tx['status'] = 'matched';
                $tx_id = AVBK_DB::insert_transaction($tx);
                self::apply_exact_fee_reference($tx_id, $exact_fee_items, $tx['counterparty_iban'], $tx['counterparty_name']);
                $matched_count++;
                continue;
            }

            $ref_member_id = AVBK_Matcher::match_reference_code($tx['description']);
            $iban_member_id = AVBK_DB::find_member_id_by_iban($tx['counterparty_iban']);
            $confident_member_id = $ref_member_id ?: $iban_member_id;

            $has_personal_one_off = (bool) array_filter($type_hints, [AVBK_Matcher::class, 'is_personal_one_off_type']);
            if ($confident_member_id && !$has_personal_one_off && AVPVH_DB::get_member($confident_member_id)) {
                $tx['status'] = 'matched';
                $tx_id = AVBK_DB::insert_transaction($tx);
                if (self::apply_payment($tx_id, [$confident_member_id], $tx['amount'], $tx['counterparty_iban'], $type_hints, $tx['counterparty_name'])) {
                    $matched_count++;
                }
                continue;
            }

            $candidates = self::find_candidates_for_row($tx['counterparty_iban'], $tx['counterparty_name'], $tx['description'], $type_hints);
            $tx['status'] = $candidates ? 'suggested' : 'unmatched';
            $tx['suggested_member_ids'] = implode(',', array_map(fn($c) => $c['member']->id, $candidates));
            $tx['suggested_type'] = implode(',', $type_hints);
            AVBK_DB::insert_transaction($tx);
        }

        AVBK_DB::update_import_batch_counts($batch_id, $row_count, $matched_count);
        return ['batch_id' => $batch_id, 'row_count' => $row_count, 'matched_count' => $matched_count];
    }

    /**
     * Re-runs matching against every still-open review-queue row (status
     * 'suggested' or 'unmatched') using the *current* AVBK_Matcher —
     * without re-reading the export file, whose rows are already in the DB
     * and would just be skipped by the dedupe check on re-upload. Needed
     * because a matcher fix/improvement doesn't retroactively touch
     * suggestions computed by the old code at import time. Returns how
     * many rows changed (newly auto-applied, or just got a different
     * suggestion).
     */
    public static function recompute_suggestions(): int {
        $changed = 0;
        foreach (AVBK_DB::get_review_queue() as $tx) {
            $type_hints = AVBK_Matcher::classify_types($tx->description);

            $exact_fee_items = self::resolve_exact_fee_reference(
                AVBK_Matcher::match_fee_item_reference($tx->description),
                (float) $tx->amount
            );
            if ($exact_fee_items) {
                self::apply_exact_fee_reference((int) $tx->id, $exact_fee_items, $tx->counterparty_iban, $tx->counterparty_name);
                $changed++;
                continue;
            }

            $ref_member_id = AVBK_Matcher::match_reference_code($tx->description);
            $iban_member_id = AVBK_DB::find_member_id_by_iban($tx->counterparty_iban);
            $confident_member_id = $ref_member_id ?: $iban_member_id;

            $has_personal_one_off = (bool) array_filter($type_hints, [AVBK_Matcher::class, 'is_personal_one_off_type']);
            if ($confident_member_id && !$has_personal_one_off && AVPVH_DB::get_member($confident_member_id)) {
                // This button promises to recalculate suggestions, not to
                // confirm them. A known IBAN/reference may preselect the
                // member, but only an exact generated QR reference remains
                // sufficiently unambiguous for automatic processing above.
                $new_ids = (string) $confident_member_id;
                $new_type = implode(',', $type_hints);
                if ($tx->status !== 'suggested' || $new_ids !== $tx->suggested_member_ids || $new_type !== $tx->suggested_type) {
                    AVBK_DB::update_transaction_suggestion((int) $tx->id, 'suggested', $new_ids, $new_type);
                    $changed++;
                }
                continue;
            }

            $candidates = self::find_candidates_for_row($tx->counterparty_iban, $tx->counterparty_name, $tx->description, $type_hints);
            $new_status = $candidates ? 'suggested' : 'unmatched';
            $new_ids = implode(',', array_map(fn($c) => $c['member']->id, $candidates));
            $new_type = implode(',', $type_hints);

            if ($new_status !== $tx->status || $new_ids !== $tx->suggested_member_ids || $new_type !== $tx->suggested_type) {
                AVBK_DB::update_transaction_suggestion((int) $tx->id, $new_status, $new_ids, $new_type);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * A known IBAN with 2+ remembered owners (joint account) is a stronger
     * signal than a fuzzy name guess — surface all of them as high-
     * confidence candidates before falling back to name matching. A single-
     * owner IBAN is handled earlier as an auto-apply, not here.
     */
    private static function find_candidates_for_row(string $counterparty_iban, string $counterparty_name, string $description, array $type_hints = []): array {
        // Drank/Eten/etc. is one person's own running account, not a family
        // contribution. Prefer the single best name match over expanding a
        // shared IBAN to every remembered household member.
        if (array_filter($type_hints, [AVBK_Matcher::class, 'is_personal_one_off_type'])) {
            $name_candidates = AVBK_Matcher::find_candidates($counterparty_name, $description);
            if (!$name_candidates) {
                return [];
            }
            $best = reset($name_candidates);
            // With no explicitly named beneficiary, narrow a fuzzy payer
            // comparison to this account's known owners. For example
            // "Mw G A de Jong" happened to score 73% against Sanne and 70%
            // against Marleen across the complete member list, although the
            // NL93 account is known to Marleen. Inside its owner pool Marleen
            // is the clear matching payer.
            if (($best['source'] ?? '') === 'payer' && $counterparty_iban !== '') {
                $iban_members = [];
                foreach (AVBK_DB::get_member_ids_by_iban($counterparty_iban) as $iban_member_id) {
                    $iban_member = AVPVH_DB::get_member((int) $iban_member_id);
                    if ($iban_member) {
                        $iban_members[] = $iban_member;
                    }
                }
                $iban_matches = $iban_members
                    ? AVBK_Matcher::find_payer_candidates($counterparty_name, $iban_members)
                    : [];
                if ($iban_matches) {
                    $best = reset($iban_matches);
                }
            }
            // When only the payer/account name matched (the memo merely says
            // "drankrekening"), a fuzzy surname hit must not make a child
            // the default account holder. Prefer an adult in that household;
            // an explicitly named child beneficiary remains untouched.
            if (($best['source'] ?? '') === 'payer' && AVBK_Matcher::member_is_minor($best['member'])) {
                $household = AVBK_DB::get_payment_household_candidates((int) $best['member']->id);
                $adults = array_values(array_filter($household, fn($member) => !AVBK_Matcher::member_is_minor($member)));
                if ($adults) {
                    $iban_owners = array_flip(AVBK_DB::get_member_ids_by_iban($counterparty_iban));
                    usort($adults, fn($a, $b) => (int) isset($iban_owners[(int) $b->id]) <=> (int) isset($iban_owners[(int) $a->id]));
                    $best = ['member' => $adults[0], 'score' => $best['score'], 'source' => 'payer'];
                }
            }
            return [$best];
        }
        if ($counterparty_iban !== '') {
            $joint_owner_ids = AVBK_DB::get_member_ids_by_iban($counterparty_iban);
            if (count($joint_owner_ids) >= 2) {
                $candidates = [];
                foreach ($joint_owner_ids as $member_id) {
                    $member = AVPVH_DB::get_member($member_id);
                    if ($member) {
                        $candidates[] = ['member' => $member, 'score' => 90];
                    }
                }
                if ($candidates) {
                    usort($candidates, fn($a, $b) =>
                        (int) AVBK_Matcher::member_is_minor($a['member']) <=> (int) AVBK_Matcher::member_is_minor($b['member'])
                    );
                    return $candidates;
                }
            }
        }
        return AVBK_Matcher::find_candidates($counterparty_name, $description);
    }

    /**
     * Resolves an exact QR reference only while every named item is still
     * open and their current remaining amounts equal the bank payment.
     * A stale/reused QR therefore falls back to human review instead of
     * silently paying a different or already-settled charge.
     *
     * @return array<int,array{item:object,remaining:float}>
     */
    private static function resolve_exact_fee_reference(array $fee_item_ids, float $transaction_amount): array {
        if (!$fee_item_ids) {
            return [];
        }
        $resolved = [];
        $total = 0.0;
        foreach ($fee_item_ids as $fee_item_id) {
            $item = AVBK_DB::get_fee_item((int) $fee_item_id);
            if (!$item || $item->status !== 'open') {
                return [];
            }
            $closed_through_year = (int) get_option('avbk_closed_through_year', 0);
            if ($closed_through_year && AVBK_DB::fee_item_book_year($item) <= $closed_through_year) {
                return []; // an old QR is revoked as soon as its book year is closed
            }
            $remaining = round((float) $item->amount_due - AVBK_DB::get_fee_item_paid((int) $item->id), 2);
            if ($remaining <= 0.005) {
                return [];
            }
            $resolved[] = ['item' => $item, 'remaining' => $remaining];
            $total += $remaining;
        }
        return abs(round($total, 2) - round($transaction_amount, 2)) <= 0.005 ? $resolved : [];
    }

    /** Applies the already-validated exact QR allocation without name/type guessing. */
    private static function apply_exact_fee_reference(int $transaction_id, array $resolved, string $iban, string $counterparty_name): void {
        $member_ids = [];
        foreach ($resolved as $entry) {
            $item = $entry['item'];
            AVBK_DB::allocate($transaction_id, (int) $item->id, (int) $item->member_id, (float) $entry['remaining']);
            $member_ids[(int) $item->member_id] = true;
        }
        foreach (array_keys($member_ids) as $member_id) {
            if ($iban !== '') {
                AVBK_DB::remember_iban((int) $member_id, $iban, $counterparty_name);
            }
        }
        if (count($member_ids) === 1) {
            self::maybe_backfill_initials((int) array_key_first($member_ids), $counterparty_name);
        }
        AVBK_DB::update_transaction_status($transaction_id, 'matched');
        AVBK_DB::clear_transaction_draft($transaction_id);
    }

    /**
     * Splits $amount evenly across $member_ids (remainder cent-rounding on
     * the last member), allocates each share to that member's open fee
     * items oldest-first, and — only when this transaction pays for
     * exactly one member — remembers the IBAN and backfills initials for
     * next time. Returns true only when the complete bank amount was
     * allocated. A partial/zero automatic attempt is rolled back and left as a review
     * suggestion; automatic matching must never produce "Gekoppeld, €0".
     */
    public static function apply_payment(int $transaction_id, array $member_ids, float $amount, string $iban, ?array $type_hints = null, string $counterparty_name = ''): bool {
        $member_ids = array_values(array_unique(array_map('intval', $member_ids)));
        if (!$member_ids) {
            return false;
        }
        // $type_hints comes from AVBK_Matcher::classify_types() as activity
        // *names* ('Kamp', 'Drank', ...) — avb_fee_items.type is the older,
        // narrower ENUM ('contribution'/'camp'/'event'/'other'), so map
        // through AVBK_DB::activity_fee_type_map() for the priority-sort
        // comparison inside allocate_to_open_items(). A hint with no fee-
        // item-type equivalent (Drank, Overig, ...) just drops out here —
        // this auto-apply path only ever pays off *existing* open items,
        // never creates a new one-off item the way the confirm form can.
        $fee_type_map = AVBK_DB::activity_fee_type_map();
        $enum_type_hints = $type_hints
            ? array_values(array_filter(array_map(fn($name) => $fee_type_map[$name] ?? null, $type_hints)))
            : null;

        // Registered one-off activities such as Weekend have no generated
        // fee-item type to allocate automatically. Identifying a member by
        // IBAN must not make their unrelated oldest contribution absorb it.
        if ($type_hints && !$enum_type_hints) {
            AVBK_DB::update_transaction_suggestion(
                $transaction_id,
                'suggested',
                implode(',', $member_ids),
                implode(',', $type_hints)
            );
            return false;
        }

        $n = count($member_ids);
        $share = round($amount / $n, 2);
        $remaining_total = $amount;

        $allocated_total = 0.0;
        foreach ($member_ids as $i => $member_id) {
            $this_share = ($i === $n - 1) ? round($remaining_total, 2) : $share;
            $remaining_total -= $this_share;
            $allocated_total += self::allocate_to_open_items($transaction_id, $member_id, $this_share, $enum_type_hints ?: null);
        }
        if ($allocated_total < $amount - 0.005) {
            AVBK_DB::clear_transaction_allocations($transaction_id);
            AVBK_DB::update_transaction_suggestion(
                $transaction_id,
                'suggested',
                implode(',', $member_ids),
                implode(',', $type_hints ?: [])
            );
            return false;
        }
        if ($n === 1) {
            if ($iban !== '') {
                AVBK_DB::remember_iban($member_ids[0], $iban, $counterparty_name);
            }
            self::maybe_backfill_initials($member_ids[0], $counterparty_name);
        }
        AVBK_DB::update_transaction_status($transaction_id, 'matched');
        return true;
    }

    /**
     * Confirms a review-queue row with an explicit treasurer-chosen split —
     * one row per (member, activity, amount). Unlike the old member-wide
     * $type_hints priority-sort, each row's activity is a deliberate,
     * explicit statement of what that money is for, so contribution and
     * camp (or drank, or anything else) for the same person in the same
     * payment can be split across separate rows instead of blended into
     * one combined amount per member.
     *
     * $rows: array of ['member_id' => int, 'activity' => string,
     * 'description' => string, 'amount' => float]. 'activity' is either
     * "a<id>" — a specific avm_activities row the treasurer picked from
     * the review queue's own dropdown, allocated against that member's
     * matching open fee item (unambiguous even when the member has two
     * open items of the same type across different years) — or any other
     * activity-type name (Drank/Eten/Overig/... — a brand new, already-paid
     * fee item is created for it on the spot, same as the old "overige
     * regel"; 'description' is optional free text folded into that item's
     * own description).
     *
     * Validates every "a<id>" row for a contribution/camp/congress before
     * writing anything: those activity types only mean something if that
     * member actually has an open generated bijdrage for it. Confirming one anyway used to
     * silently mark the transaction "matched" with €0 actually allocated —
     * e.g. a congres-betaling confirmed before the aanmeldingen-sheet had
     * been imported, so the bijdrage-regel didn't exist yet at confirm
     * time (found 2026-08-29: several congres-betalingen stuck at
     * "Gekoppeld" with nothing paid off). Now it's refused up front instead,
     * with a specific reden per rij, so the money is never lost track of.
     *
     * @return array{ok: bool, errors: string[], underpaid?: float, requested_total?: float, remaining_open?: float, unassigned?: float} errors is empty iff ok.
     */
    public static function confirm_transaction(int $transaction_id, array $rows): array {
        $tx = AVBK_DB::get_transaction($transaction_id);
        if (!$tx) {
            return ['ok' => false, 'errors' => ['Transactie niet gevonden.']];
        }

        $errors = [];
        $row_total = round(array_sum(array_map(fn($row) => (float) ($row['amount'] ?? 0), $rows)), 2);
        $transaction_amount = round((float) $tx->amount, 2);
        // A payment may legitimately exceed every currently open charge.
        // Process the assignable part and keep the excess visible as an
        // unassigned amount in the confirmation notice/transaction record;
        // it must not trap the treasurer on this queue item forever.
        $unassigned = 0.0;

        // Underpayment is valid bookkeeping: allocate no more than was
        // actually received (top-to-bottom, matching the visible row
        // order), then leave the unpaid remainder open on the selected
        // charges. Never store allocations whose sum exceeds the bank row.
        $underpaid = max(0, round($row_total - $transaction_amount, 2));
        if ($underpaid > 0.005) {
            $remaining_received = $transaction_amount;
            foreach ($rows as &$row) {
                $requested = max(0, round((float) ($row['amount'] ?? 0), 2));
                $row['requested_amount'] = $requested;
                $row['amount'] = min($requested, $remaining_received);
                $remaining_received = max(0, round($remaining_received - $row['amount'], 2));
            }
            unset($row);
            $rows = array_values(array_filter($rows, fn($row) => (float) $row['amount'] > 0.005));
        }
        $selected_open_charges = [];
        foreach ($rows as $row) {
            $member_id = (int) ($row['member_id'] ?? 0);
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $activity = trim((string) ($row['activity'] ?? ''));
            if ($member_id <= 0 || $amount <= 0 || $activity === '' || !preg_match('/^a(\d+)$/', $activity, $m)) {
                continue;
            }
            $activity_obj = AVPVH_DB::get_activity((int) $m[1]);
            if (!$activity_obj) {
                $errors[] = "Activiteit #{$m[1]} bestaat niet meer.";
                continue;
            }
            // Contributie/Kamp/Congres are backed by a generated charge
            // (membership, participation, or external registration). A
            // registered Weekend/Feest/etc. may deliberately start without
            // an imported participant list; its entered amount is a valid
            // one-off charge tied to that concrete activity, and confirming
            // it creates the missing participation row below.
            $requires_generated_fee = isset(AVBK_DB::activity_fee_type_map()[$activity_obj->type_name]);
            $open_due = $requires_generated_fee ? self::open_due_for_activity($member_id, (int) $m[1]) : 0.0;
            if ($requires_generated_fee && $open_due <= 0) {
                $member = AVPVH_DB::get_member($member_id);
                $participation = AVPVH_DB::get_participation($member_id, (int) $m[1]);
                $participation_url_args = [
                    'page'        => 'avpvh-activity-participation-detail',
                    'activity_id' => (int) $m[1],
                    // The members form ignores this for an existing row,
                    // but the bookkeeping prefill script uses it when this
                    // link opens a brand-new participation.
                    'member_id'   => $member_id,
                ];
                if ($participation) {
                    $participation_url_args['id'] = (int) $participation->id;
                }
                $participation_url = add_query_arg($participation_url_args, admin_url('admin.php'));
                $member_name = $member ? avpvh_format_name($member, 'list') : "lid #{$member_id}";
                $short_member_name = $member ? $member->first_name : "lid #{$member_id}";
                $activity_name = $activity_obj->name ?? "activiteit #{$m[1]}";
                $registration_name = ($activity_obj->type_name ?? '') === 'Kamp'
                    ? 'kampinschrijving'
                    : 'deelname';
                $link_label = $participation
                    ? 'pas de ' . $registration_name . ' van ' . $short_member_name . ' aan'
                    : 'voeg de ' . $registration_name . ' van ' . $short_member_name . ' toe';
                $errors[] = esc_html($member_name) . ': geen openstaande bijdrage voor '
                    . esc_html($activity_name) . ' — <a href="' . esc_url($participation_url)
                    . '" target="_blank" rel="noopener">' . esc_html($link_label)
                    . '</a>, of kies een andere activiteit.';
            } elseif ($requires_generated_fee) {
                $charge_key = $member_id . ':' . (int) $m[1];
                if (!isset($selected_open_charges[$charge_key])) {
                    $selected_open_charges[$charge_key] = ['open' => $open_due, 'assigned' => 0.0];
                }
                $selected_open_charges[$charge_key]['assigned'] += $amount;
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        // The bank amount can be distributed completely while an individual
        // selected charge is only partly paid (for example €80 assigned to
        // a €140 camp contribution). Keep processing, but report the unpaid
        // remainder explicitly instead of presenting this as an unqualified
        // successful full payment.
        $remaining_open = 0.0;
        foreach ($selected_open_charges as $charge) {
            $remaining_open += max(0, round($charge['open'] - $charge['assigned'], 2));
        }
        $remaining_open = round($remaining_open, 2);

        $paid_member_ids = [];
        foreach ($rows as $row) {
            $member_id = (int) ($row['member_id'] ?? 0);
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $activity = trim((string) ($row['activity'] ?? ''));
            if ($member_id <= 0 || $amount <= 0 || $activity === '') {
                continue;
            }
            if (preg_match('/^a(\d+)$/', $activity, $m)) {
                $activity_id = (int) $m[1];
                $activity_obj = AVPVH_DB::get_activity($activity_id);
                $requires_generated_fee = $activity_obj
                    && isset(AVBK_DB::activity_fee_type_map()[$activity_obj->type_name]);
                if ($requires_generated_fee) {
                    self::allocate_to_open_items_of_activity($transaction_id, $member_id, $amount, $activity_id);
                } else {
                    // A sheet/list is an optional source of registrations.
                    // When the first evidence is the actual payment, make
                    // the payer a participant so the activity's participant
                    // overview is built up from confirmed payments. Never
                    // re-save an existing row with blanks: that would erase
                    // diet/notes entered through another registration path.
                    if (!AVPVH_DB::get_participation($member_id, $activity_id)) {
                        AVPVH_DB::save_participation($member_id, $activity_id, [
                            'nights'  => null,
                            'nawacht' => false,
                            'diet'    => '',
                            'notes'   => 'Deelname aangemaakt bij verwerking van betaling.',
                        ]);
                    }
                    $category = $activity_obj->name ?? "Activiteit #{$activity_id}";
                    $amount_due = max($amount, (float) ($row['requested_amount'] ?? 0));
                    $fee_item_id = AVBK_DB::create_other_fee_item($member_id, $category, '', $amount_due, $activity_id);
                    AVBK_DB::allocate($transaction_id, $fee_item_id, $member_id, $amount);
                }
            } else {
                $description = (string) ($row['description'] ?? '');
                $amount_due = max($amount, (float) ($row['requested_amount'] ?? 0));
                $fee_item_id = AVBK_DB::create_other_fee_item($member_id, $activity, $description, $amount_due);
                AVBK_DB::allocate($transaction_id, $fee_item_id, $member_id, $amount);
            }
            $paid_member_ids[] = $member_id;
        }
        $paid_member_ids = array_values(array_unique($paid_member_ids));

        // The IBAN is safe to remember for every payer on a split payment —
        // avb_known_ibans is many-to-many, and a joint account with 2+
        // remembered owners is only ever surfaced as candidates to confirm
        // (see find_candidates_for_row()), never auto-applied — so this
        // never causes a silent misallocation, only a better default
        // suggestion the next time this account pays (e.g. a whole family
        // sharing one account).
        if ($tx->counterparty_iban !== '') {
            foreach ($paid_member_ids as $member_id) {
                AVBK_DB::remember_iban($member_id, $tx->counterparty_iban, $tx->counterparty_name);
            }
        }
        // Initials backfill stays single-payer-only: it parses one name
        // string ("S J M Jansen") into initials for one specific member,
        // and a split transaction gives no way to know which part of that
        // name belongs to which of several payers.
        if (count($paid_member_ids) === 1) {
            self::maybe_backfill_initials($paid_member_ids[0], $tx->counterparty_name);
        }
        // Use what was actually allocated rather than merely summing the
        // form: an entered row can itself exceed the selected charge's open
        // balance, in which case its excess is unassigned too.
        $allocated_total = array_sum(array_map(
            fn($allocation) => (float) $allocation->amount,
            AVBK_DB::get_allocations_for_transaction($transaction_id)
        ));
        $unassigned = max(0, round($transaction_amount - $allocated_total, 2));
        AVBK_DB::update_transaction_status($transaction_id, 'matched');
        AVBK_DB::clear_transaction_draft($transaction_id);
        return [
            'ok'              => true,
            'errors'          => [],
            'underpaid'       => $underpaid,
            'requested_total' => $row_total,
            'remaining_open'  => $remaining_open,
            'unassigned'      => $unassigned,
        ];
    }

    /** Total still owed on $member_id's open fee item(s) for this specific activiteit — 0 if there isn't one (yet). */
    private static function open_due_for_activity(int $member_id, int $activity_id): float {
        $due = 0.0;
        foreach (AVBK_DB::get_open_fee_items_for_member($member_id) as $item) {
            if ((int) $item->activity_id === $activity_id) {
                $due += max(0, round((float) $item->amount_due - AVBK_DB::get_fee_item_paid((int) $item->id), 2));
            }
        }
        return round($due, 2);
    }

    /**
     * Recognizes "<initials> <surname>" in a bank transaction's own payer
     * name (e.g. "S J M Jansen") and, only when the member doesn't already
     * have initials on file, saves it — never overwrites a value someone
     * already entered, since that may have been sourced from an actual
     * passport rather than guessed from one bank transaction.
     */
    private static function maybe_backfill_initials(int $member_id, string $counterparty_name): void {
        if ($counterparty_name === '') {
            return;
        }
        $member = AVPVH_DB::get_member($member_id);
        if (!$member || !empty($member->initials)) {
            return;
        }
        $initials = AVBK_Matcher::extract_initials($counterparty_name, $member->last_name);
        if ($initials) {
            AVPVH_DB::update_member_initials($member_id, $initials);
        }
    }

    /**
     * Allocates $amount to a member's open fee items, oldest first
     * (matching any of $type_hints first when given — a "kamp en
     * contributie" payment can rightly pay off both fee types from one
     * transaction), leaving any un-allocatable remainder simply
     * unallocated — an overpayment or a payment with nothing open to
     * apply it to isn't modeled as a credit balance in this version; the
     * treasurer sees it as a partly-applied transaction in "Alle
     * transacties".
     */
    private static function allocate_to_open_items(int $transaction_id, int $member_id, float $amount, ?array $type_hints): float {
        $open_items = AVBK_DB::get_open_fee_items_for_member($member_id);
        if ($type_hints) {
            // A description saying "congres/feest" must never fall through
            // to an unrelated open Weekend or Contributie charge merely
            // because the IBAN identifies the member.
            $open_items = array_values(array_filter(
                $open_items,
                fn($item) => in_array($item->type, $type_hints, true)
            ));
        }

        $remaining = round($amount, 2);
        foreach ($open_items as $item) {
            if ($remaining <= 0) {
                break;
            }
            $due_left = round((float) $item->amount_due - AVBK_DB::get_fee_item_paid((int) $item->id), 2);
            if ($due_left <= 0) {
                continue;
            }
            $alloc = min($remaining, $due_left);
            AVBK_DB::allocate($transaction_id, (int) $item->id, $member_id, $alloc);
            $remaining = round($remaining - $alloc, 2);
        }
        return round($amount - $remaining, 2);
    }

    /**
     * Allocates $amount to a member's open fee item for exactly one
     * specific activity — the confirm-form counterpart to
     * allocate_to_open_items() above (used by the fully-automatic
     * apply_payment() path, which only has a guessed list of possible
     * types to blend). Here the treasurer picked one specific activity for
     * this one specific row, so there's no blending or fallback to other
     * activities/types: money explicitly assigned to "Kamp Zonneveld
     * (2026)" never quietly pays off a different year's camp fee, or a
     * contribution item, instead. Any remainder that doesn't fit this
     * activity's own open item is simply left unallocated, same as
     * allocate_to_open_items().
     */
    private static function allocate_to_open_items_of_activity(int $transaction_id, int $member_id, float $amount, int $activity_id): void {
        $open_items = array_values(array_filter(
            AVBK_DB::get_open_fee_items_for_member($member_id),
            fn($item) => (int) $item->activity_id === $activity_id
        ));

        $remaining = round($amount, 2);
        foreach ($open_items as $item) {
            if ($remaining <= 0) {
                break;
            }
            $due_left = round((float) $item->amount_due - AVBK_DB::get_fee_item_paid((int) $item->id), 2);
            if ($due_left <= 0) {
                continue;
            }
            $alloc = min($remaining, $due_left);
            AVBK_DB::allocate($transaction_id, (int) $item->id, $member_id, $alloc);
            $remaining = round($remaining - $alloc, 2);
        }
    }
}
