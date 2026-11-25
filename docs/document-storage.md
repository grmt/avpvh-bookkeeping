# Document storage configuration

Receipt downloads go through the plugin's authenticated PHP handlers.
Configure the web server to deny direct access to the entire storage
folder, including PDFs and images.

For Nginx/OpenResty, include `docs/nginx-documents.conf` inside every
HTTP/HTTPS server block that serves the installation, before other regular
expression locations. Check for static aliases or `^~` locations that bypass
regular expression locations; configure the same restriction there. Run
`nginx -t` before reloading the server. Apache uses the `.htaccess` written
in the storage directory.

After deployment, verify a direct document URL returns 404 for an anonymous
GET and HEAD, including through any proxy/cache. Purge cached document URLs.
Verify the authorized download handler still serves the document, and
rejects another member or a logged-out visitor.

For storage outside the document root, set `AVBK_RECEIPTS_DIR` in
`wp-config.php` to an absolute private directory readable and writable by
PHP. During a maintenance window, move all existing files from
`WP_CONTENT_DIR/avbk-receipts` there, preserving filenames, ownership and
permissions, then set the constant. No database path rewrite is needed.
Remove public copies after verifying the authorized handlers. Keep the web
server restriction for old URLs. Do not configure an alias for the private
directory.
