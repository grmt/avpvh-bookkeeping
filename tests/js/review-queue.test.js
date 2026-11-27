// Te controleren: the "bewerk lid" and saldo links next to a row's member.
import { beforeEach, describe, expect, it, vi } from 'vitest';

let start;

/** One review form with one split row for the given member field value. */
function load(memberValue) {
    document.body.innerHTML = `
        <script type="application/json" id="avbk-review-config">${JSON.stringify({ ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n', members: [] })}</script>
        <form class="avbk-review-form" data-tx-description="">
            <table class="avbk-review-split"><tr>
                <td><input type="hidden" name="member_id[]" value="${memberValue}"></td>
                <td><select name="activity[]"><option value="">—</option></select></td>
                <td><input name="amount[]" value="10,00"></td>
                <td><a class="avbk-detail-member-link" href="#">bewerk lid</a>
                    <a class="avbk-detail-member-balance-link" href="#">saldo</a></td>
            </tr></table>
        </form>`;
    start();
}

const link = (selector) => document.querySelector(selector);

beforeEach(async () => {
    const listen = vi.spyOn(document, 'addEventListener');
    vi.resetModules();
    await import('../../assets/review-queue.js');
    start = listen.mock.calls.find(([type]) => type === 'DOMContentLoaded')[1];
    listen.mockRestore();
});

describe('member links', () => {
    it('point at the member and their balance, relative to this admin page', () => {
        load('12');
        expect(link('.avbk-detail-member-link').getAttribute('href')).toBe('admin.php?page=avpvh-member-detail&id=12');
        expect(link('.avbk-detail-member-balance-link').getAttribute('href')).toBe('admin.php?page=avbk-members&member_id=12');
        expect(link('.avbk-detail-member-link').style.display).toBe('');
    });

    it('are hidden without a member, or with anything but a member id', () => {
        for (const value of ['', 'javascript:alert(1)', '0']) {
            load(value);
            expect(link('.avbk-detail-member-link').style.display).toBe('none');
            expect(link('.avbk-detail-member-balance-link').style.display).toBe('none');
        }
    });
});
