<?php
/**
 * Email fetching and HTML extraction for Indivisible Newsletter Poster.
 *
 * Uses native PHP sockets to connect to IMAP servers, since the PHP IMAP
 * extension (libc-client) is unavailable on modern Debian (Trixie+).
 *
 * Connects to an IMAP mailbox, finds qualified newsletter emails,
 * and extracts the HTML content (replacing the manual ProofJump step).
 */

if (!defined('ABSPATH')) {
    exit;
}

// Repeated string constants used across IMAP functions.
define( 'IN_IMAP_SELECT_PREFIX', 'SELECT "' );
define( 'IN_IMAP_SEARCH_REGEX',  '/^\*\s+SEARCH\s+([\d\s]+)/i' );
define( 'IN_IMAP_SPLIT_WS',      '/\s+/' );
define( 'IN_LOG_MSG_PREFIX',     'Newsletter Poster: Message #' );
define( 'IN_LOG_NONE',           '(none)' );
define( 'IN_LOG_BYTES_SUFFIX',      ' bytes)' );
define( 'IN_LOG_SEARCH_FAILED',     'SEARCH FAILED: ' );
define( 'IN_IMAP_SEARCH_UNSEEN',    'SEARCH UNSEEN' );

/**
 * Test the IMAP connection with current settings.
 *
 * @return string|WP_Error Success message or error.
 */
function indivisible_newsletter_test_connection() {
    $settings = indivisible_newsletter_get_settings();

    if (empty($settings['imap_host']) || empty($settings['email_username']) || empty($settings['email_password'])) {
        return new WP_Error('missing_settings', 'IMAP host, username, and password are required.');
    }

    $password = indivisible_newsletter_decrypt($settings['email_password']);
    $conn = indivisible_newsletter_imap_connect($settings, $password);

    if (is_wp_error($conn)) {
        return $conn;
    }

    // Select mailbox to get message count.
    $select = indivisible_newsletter_imap_command($conn, IN_IMAP_SELECT_PREFIX . $settings['imap_folder'] . '"');
    $count = 0;
    if (!is_wp_error($select)) {
        foreach ($select as $line) {
            if (preg_match('/\*\s+(\d+)\s+EXISTS/i', $line, $m)) {
                $count = (int) $m[1];
            }
        }
    }

    indivisible_newsletter_imap_command($conn, 'LOGOUT');
    fclose($conn);

    return "Connection successful! Mailbox has {$count} message(s).";
}

/**
 * Parse UIDs from IMAP SEARCH response lines.
 *
 * @param array $lines Response lines from an IMAP SEARCH command.
 * @return array Integer UIDs found in the response.
 */
function indivisible_newsletter_imap_parse_search_uids(array $lines): array {
    $uids = array();
    foreach ($lines as $line) {
        if (preg_match(IN_IMAP_SEARCH_REGEX, $line, $m)) {
            $uid_str = trim($m[1]);
            if ($uid_str !== '') {
                $uids = array_merge($uids, array_filter(array_map('intval', preg_split(IN_IMAP_SPLIT_WS, $uid_str))));
            }
        }
    }
    return $uids;
}

/**
 * Search the mailbox for message UIDs, optionally filtered by qualified senders.
 *
 * @param resource $conn     IMAP connection.
 * @param array    $settings Plugin settings.
 * @return array Sorted, deduplicated integer UIDs.
 */
function indivisible_newsletter_imap_search_uids($conn, array $settings): array {
    $senders = array();
    if (!empty($settings['filter_by_sender']) && !empty($settings['qualified_senders'])) {
        $senders = array_filter(array_map('trim', explode("\n", $settings['qualified_senders'])));
    }

    $uids = array();
    if (!empty($senders)) {
        // Run a separate SEARCH for each sender and merge results.
        foreach ($senders as $sender) {
            $result = indivisible_newsletter_imap_command($conn, 'SEARCH FROM "' . $sender . '"');
            if (!is_wp_error($result)) {
                $uids = array_merge($uids, indivisible_newsletter_imap_parse_search_uids($result));
            }
        }
        $uids = array_unique($uids);
        sort($uids);
    } else {
        $result = indivisible_newsletter_imap_command($conn, 'SEARCH ALL');
        if (!is_wp_error($result)) {
            $uids = indivisible_newsletter_imap_parse_search_uids($result);
        }
    }

    return $uids;
}

/**
 * Fetch and process a single IMAP message, appending to $emails if HTML content is found.
 *
 * @param resource $conn          IMAP connection.
 * @param int      $uid           Message sequence number.
 * @param array    $processed_ids Already-processed Message-IDs.
 * @param array    $emails        Result array (passed by reference).
 */
function indivisible_newsletter_fetch_process_message($conn, int $uid, array $processed_ids, array &$emails): void {
    error_log(IN_LOG_MSG_PREFIX . $uid);

    $header_data = indivisible_newsletter_imap_fetch_section($conn, $uid, 'HEADER');
    if (empty($header_data)) {
        error_log(IN_LOG_MSG_PREFIX . $uid . ' - header fetch returned empty');
        return;
    }
    error_log(IN_LOG_MSG_PREFIX . $uid . ' - headers fetched (' . strlen($header_data) . IN_LOG_BYTES_SUFFIX);

    $headers    = indivisible_newsletter_parse_headers($header_data);
    $message_id = $headers['message-id'] ?? '';

    error_log(IN_LOG_MSG_PREFIX . $uid . ' - Message-ID: ' . $message_id);
    error_log(IN_LOG_MSG_PREFIX . $uid . ' - Subject: ' . ($headers['subject'] ?? IN_LOG_NONE));

    if (!empty($message_id) && in_array($message_id, $processed_ids, true)) {
        error_log(IN_LOG_MSG_PREFIX . $uid . ' - SKIPPED (already processed)');
        return;
    }

    $subject   = indivisible_newsletter_decode_mime_header($headers['subject'] ?? 'Newsletter');
    $body_data = indivisible_newsletter_imap_fetch_section($conn, $uid, '');

    error_log(IN_LOG_MSG_PREFIX . $uid . ' - fetching full body...');
    error_log(IN_LOG_MSG_PREFIX . $uid . ' - body fetched (' . strlen($body_data) . IN_LOG_BYTES_SUFFIX);

    $html = '';
    if (!empty($body_data)) {
        file_put_contents('/tmp/newsletter_debug_msg_' . $uid . '.txt', $body_data);
        error_log(IN_LOG_MSG_PREFIX . $uid . ' - body saved to /tmp/newsletter_debug_msg_' . $uid . '.txt');
        $html = indivisible_newsletter_extract_html_from_raw($body_data);
        error_log(IN_LOG_MSG_PREFIX . $uid . ' - HTML extracted (' . strlen($html) . IN_LOG_BYTES_SUFFIX);
    } else {
        error_log(IN_LOG_MSG_PREFIX . $uid . ' - body fetch returned empty');
    }

    if (!empty($html)) {
        $emails[] = array(
            'message_id' => $message_id,
            'subject'    => $subject,
            'html'       => $html,
            'date'       => $headers['date'] ?? '',
            'uid'        => $uid,
        );
        error_log(IN_LOG_MSG_PREFIX . $uid . ' - QUEUED for post creation');
    } else {
        error_log(IN_LOG_MSG_PREFIX . $uid . ' - SKIPPED (no HTML content found)');
    }

    indivisible_newsletter_imap_command($conn, 'STORE ' . $uid . ' +FLAGS (\\Seen)');
}

/**
 * Fetch qualified emails from the IMAP mailbox.
 *
 * @return array|WP_Error Array of email data or error.
 */
function indivisible_newsletter_fetch_emails() {
    $settings = indivisible_newsletter_get_settings();

    if (empty($settings['imap_host']) || empty($settings['email_username']) || empty($settings['email_password'])) {
        return new WP_Error('missing_settings', 'IMAP settings are not configured.');
    }

    $password = indivisible_newsletter_decrypt($settings['email_password']);
    $conn     = indivisible_newsletter_imap_connect($settings, $password);

    if (is_wp_error($conn)) {
        return $conn;
    }

    $select = indivisible_newsletter_imap_command($conn, IN_IMAP_SELECT_PREFIX . $settings['imap_folder'] . '"');
    if (is_wp_error($select)) {
        fclose($conn);
        return $select;
    }

    // Search for messages (uses processed IDs list, not UNSEEN flag, to avoid duplicates
    // with messages already read in a mail client).
    $uids = indivisible_newsletter_imap_search_uids($conn, $settings);
    error_log('Newsletter Poster: Search returned ' . count($uids) . ' message(s): ' . implode(', ', $uids));

    if (empty($uids)) {
        indivisible_newsletter_imap_command($conn, 'LOGOUT');
        fclose($conn);
        return array();
    }

    $processed_ids = get_option(IN_PROCESSED_KEY, array());
    error_log('Newsletter Poster: ' . count($processed_ids) . ' previously processed ID(s)');

    $emails = array();
    foreach ($uids as $uid) {
        indivisible_newsletter_fetch_process_message($conn, $uid, $processed_ids, $emails);
    }

    indivisible_newsletter_imap_command($conn, 'LOGOUT');
    fclose($conn);

    return $emails;
}

/**
 * Connect to the IMAP server and authenticate.
 *
 * @param array  $settings Plugin settings.
 * @param string $password Decrypted password.
 * @return resource|WP_Error Socket connection or error.
 */
function indivisible_newsletter_imap_connect($settings, $password) {
    $host = $settings['imap_host'];
    $port = (int) $settings['imap_port'];
    $encryption = $settings['imap_encryption'];

    $context = stream_context_create();
    $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $errno  = 0;
    $errstr = '';
    // Allow tests to inject a pre-connected stream (filter returns null in production).
    $conn = apply_filters('indivisible_newsletter_imap_socket_client', null, $address);
    if (null === $conn) {
        $conn = @stream_socket_client($address, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    }
    if (!$conn) {
        return new WP_Error('connection_failed', "Could not connect to {$host}:{$port} - {$errstr}");
    }

    // Read server greeting.
    $greeting = fgets($conn, 4096);
    if (strpos($greeting, '* OK') === false) {
        fclose($conn);
        return new WP_Error('connection_failed', 'Unexpected server greeting: ' . trim($greeting));
    }

    // STARTTLS if needed.
    if ($encryption === 'tls') {
        $starttls = indivisible_newsletter_imap_command($conn, 'STARTTLS');
        if (is_wp_error($starttls)) {
            fclose($conn);
            return $starttls;
        }
        $crypto_result = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        if (!$crypto_result) {
            fclose($conn);
            return new WP_Error('tls_failed', 'Failed to enable TLS encryption.');
        }
    }

    // Login.
    $username = $settings['email_username'];
    // Escape quotes in username and password for IMAP literal.
    $login_result = indivisible_newsletter_imap_command(
        $conn,
        'LOGIN "' . addcslashes($username, '"\\') . '" "' . addcslashes($password, '"\\') . '"'
    );

    if (is_wp_error($login_result)) {
        fclose($conn);
        return new WP_Error('auth_failed', 'Authentication failed. Check your username and password.');
    }

    return $conn;
}

/**
 * Send an IMAP command and read the response.
 *
 * @param resource $conn    Socket connection.
 * @param string   $command IMAP command (without tag).
 * @return array|WP_Error Response lines or error.
 */
function indivisible_newsletter_imap_command($conn, $command) {
    global $in_imap_tag_counter;
    if ( ! isset( $in_imap_tag_counter ) ) {
        $in_imap_tag_counter = 0;
    }
    $in_imap_tag_counter++;
    $tag = 'A' . str_pad($in_imap_tag_counter, 4, '0', STR_PAD_LEFT);

    $full_command = $tag . ' ' . $command . "\r\n";
    fwrite($conn, $full_command);

    $response = array();
    $timeout = 30;
    $start = time();

    while (true) {
        if (time() - $start > $timeout) {
            return new WP_Error('timeout', 'IMAP command timed out: ' . $command);
        }

        $line = fgets($conn, 8192);
        if ($line === false) {
            return new WP_Error('read_error', 'Failed to read IMAP response.');
        }

        $response[] = $line;

        // Check for tagged response (completion).
        if (strpos($line, $tag . ' OK') === 0) {
            return $response;
        }
        if (strpos($line, $tag . ' NO') === 0 || strpos($line, $tag . ' BAD') === 0) {
            return new WP_Error('imap_error', 'IMAP error: ' . trim($line));
        }
    }
}

/**
 * Read exactly $size bytes from $conn, returning the data or false on error.
 *
 * @param resource $conn Socket connection.
 * @param int      $size Number of bytes to read.
 * @return string|false
 */
function indivisible_newsletter_imap_read_literal($conn, int $size) {
    $data      = '';
    $remaining = $size;
    while ($remaining > 0) {
        $chunk = fread($conn, min($remaining, 8192));
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $data      .= $chunk;
        $remaining -= strlen($chunk);
    }
    return $data;
}

/**
 * Build the IMAP FETCH command string for a given section.
 *
 * @param string $tag     IMAP command tag.
 * @param int    $msg_num Message sequence number.
 * @param string $section Section specifier ('HEADER', '', or a part number).
 * @return string Full IMAP command line including CRLF.
 */
function indivisible_newsletter_imap_build_fetch_cmd(string $tag, $msg_num, string $section): string {
    if ($section === 'HEADER') {
        $body_spec = 'BODY[HEADER]';
    } elseif ($section === '') {
        $body_spec = 'BODY[]';
    } else {
        $body_spec = 'BODY[' . $section . ']';
    }
    return $tag . ' FETCH ' . $msg_num . ' ' . $body_spec . "\r\n";
}

/**
 * Fetch a section of a message (headers or body).
 *
 * @param resource $conn    Socket connection.
 * @param int      $msg_num Message sequence number.
 * @param string   $section Section to fetch (e.g., 'HEADER', '', '1', '1.2').
 * @return string Fetched content or empty string.
 */
function indivisible_newsletter_imap_fetch_section($conn, $msg_num, $section) {
    global $in_imap_fetch_counter;
    if ( ! isset( $in_imap_fetch_counter ) ) {
        $in_imap_fetch_counter = 1000;
    }
    $in_imap_fetch_counter++;
    $tag = 'F' . $in_imap_fetch_counter;

    fwrite($conn, indivisible_newsletter_imap_build_fetch_cmd($tag, $msg_num, $section));

    $data    = '';
    $timeout = 60;
    $start   = time();

    while (true) {
        if (time() - $start > $timeout) {
            break;
        }

        $line = fgets($conn, 8192);
        if ($line === false) {
            break;
        }

        // Check for literal size indicator {NNN}: read that many bytes immediately.
        if (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
            $literal = indivisible_newsletter_imap_read_literal($conn, (int) $m[1]);
            if ($literal === false) {
                break;
            }
            $data = $literal;
            continue;
        }

        // Check for tagged completion (OK, NO, or BAD).
        if (strpos($line, $tag . ' OK') === 0 || strpos($line, $tag . ' NO') === 0 || strpos($line, $tag . ' BAD') === 0) {
            break;
        }
    }

    return $data;
}

/**
 * Extract HTML content from a raw email message.
 *
 * Parses the MIME structure to find the text/html part.
 * This replaces the ProofJump external tool.
 *
 * @param string $raw_message Raw email message.
 * @return string HTML content or empty string.
 */
function indivisible_newsletter_extract_html_from_raw($raw_message) {
    // Split headers from body.
    $parts = preg_split('/\r?\n\r?\n/', $raw_message, 2);
    if (count($parts) < 2) {
        return '';
    }

    $headers = $parts[0];
    $body    = $parts[1];

    // Unfold continuation headers (lines starting with whitespace are continuations).
    $headers = preg_replace('/\r?\n[ \t]+/', ' ', $headers);

    // Get content type (now on a single line after unfolding).
    $content_type = '';
    if (preg_match('/^Content-Type:\s*(.+)$/im', $headers, $m)) {
        $content_type = trim($m[1]);
    }

    // Check if this is directly HTML.
    if (stripos($content_type, 'text/html') !== false) {
        $encoding = '';
        if (preg_match('/^Content-Transfer-Encoding:\s*(\S+)/im', $headers, $m)) {
            $encoding = strtolower(trim($m[1]));
        }
        return indivisible_newsletter_decode_transfer_encoding($body, $encoding);
    }

    // Check if multipart.
    if (preg_match('/boundary="?([^";\s]+)"?/i', $content_type, $m)) {
        $boundary = $m[1];
        return indivisible_newsletter_find_html_in_multipart($body, $boundary);
    }

    return '';
}

/**
 * Process a single decoded MIME part and return HTML content if found.
 *
 * Returns the decoded HTML body if the part is text/html, or recursively
 * searches nested multipart parts. Returns empty string otherwise.
 *
 * @param string $part_headers Unfolded headers for this part.
 * @param string $part_body    Body content for this part.
 * @param string $part_ct      Content-Type value for this part.
 * @return string HTML content or empty string.
 */
function indivisible_newsletter_process_mime_part( $part_headers, $part_body, $part_ct ) {
    if ( stripos( $part_ct, 'text/html' ) !== false ) {
        $encoding = '';
        if ( preg_match( '/^Content-Transfer-Encoding:\s*(\S+)/im', $part_headers, $m ) ) {
            $encoding = strtolower( trim( $m[1] ) );
        }
        return indivisible_newsletter_decode_transfer_encoding( $part_body, $encoding );
    }
    if ( preg_match( '/boundary="?([^";\s]+)"?/i', $part_ct, $m ) ) {
        return indivisible_newsletter_find_html_in_multipart( $part_body, $m[1] );
    }
    return '';
}

/**
 * Search multipart MIME body for HTML part.
 *
 * @param string $body     Message body.
 * @param string $boundary MIME boundary string.
 * @return string HTML content or empty string.
 */
function indivisible_newsletter_find_html_in_multipart( $body, $boundary ) {
    $parts = explode( '--' . $boundary, $body );

    foreach ( $parts as $part ) {
        $part = ltrim( $part, "\r\n" );

        if ( empty( $part ) || strpos( $part, '--' ) === 0 ) {
            continue;
        }

        $sections = preg_split( '/\r?\n\r?\n/', $part, 2 );
        if ( count( $sections ) < 2 ) {
            continue;
        }

        $part_headers = preg_replace( '/\r?\n[ \t]+/', ' ', $sections[0] );
        $part_body    = preg_replace( '/\r?\n--' . preg_quote( $boundary, '/' ) . '--?\s*$/', '', $sections[1] );
        $part_ct      = '';
        if ( preg_match( '/^Content-Type:\s*(.+)$/im', $part_headers, $m ) ) {
            $part_ct = trim( $m[1] );
        }

        $result = indivisible_newsletter_process_mime_part( $part_headers, $part_body, $part_ct );
        if ( ! empty( $result ) ) {
            return $result;
        }
    }

    return '';
}

/**
 * Decode content based on Content-Transfer-Encoding.
 *
 * @param string $body     Encoded body.
 * @param string $encoding Encoding type (base64, quoted-printable, 7bit, 8bit).
 * @return string Decoded body.
 */
function indivisible_newsletter_decode_transfer_encoding($body, $encoding) {
    switch ($encoding) {
        case 'base64':
            return base64_decode($body);
        case 'quoted-printable':
            return quoted_printable_decode($body);
        case '7bit':
        case '8bit':
        case 'binary':
        default:
            return $body;
    }
}

/**
 * Parse email headers into an associative array.
 *
 * @param string $header_text Raw header text.
 * @return array Headers as key => value (lowercase keys).
 */
function indivisible_newsletter_parse_headers($header_text) {
    $headers = array();
    // Unfold continued headers (lines starting with whitespace).
    $header_text = preg_replace('/\r?\n\s+/', ' ', $header_text);
    $lines = preg_split('/\r?\n/', $header_text);

    foreach ($lines as $line) {
        if (preg_match('/^([^:]+):\s*(.+)/', $line, $m)) {
            $key = strtolower(trim($m[1]));
            $headers[$key] = trim($m[2]);
        }
    }

    return $headers;
}

/**
 * Run per-sender or all-mailbox UNSEEN search and append results to $report.
 *
 * @param resource $conn         IMAP connection.
 * @param array    $diag_senders Qualified sender addresses, or empty for all.
 * @param array    $report       Report array (passed by reference).
 */
function indivisible_newsletter_diagnose_sender_searches($conn, array $diag_senders, array &$report): void {
    if (!empty($diag_senders)) {
        foreach ($diag_senders as $sender) {
            $report[] = '--- Searching: UNSEEN FROM "' . $sender . '" ---';
            indivisible_newsletter_diagnose_run_search($conn, 'SEARCH UNSEEN FROM "' . $sender . '"', $report);
        }
    } else {
        $report[] = '--- Searching: UNSEEN (sender filter disabled) ---';
        indivisible_newsletter_diagnose_run_search($conn, IN_IMAP_SEARCH_UNSEEN, $report);
    }
    $report[] = '';
}

/**
 * Run one IMAP command and append the response lines (or error) to $report.
 *
 * @param resource $conn    IMAP connection.
 * @param string   $command IMAP command string (without tag).
 * @param array    $report  Report array (passed by reference).
 */
function indivisible_newsletter_diagnose_run_search($conn, string $command, array &$report): void {
    $result = indivisible_newsletter_imap_command($conn, $command);
    if (is_wp_error($result)) {
        $report[] = IN_LOG_SEARCH_FAILED . $result->get_error_message();
        return;
    }
    foreach ($result as $line) {
        $report[] = '  ' . trim($line);
    }
}

/**
 * Append headers and flags for a single message to $report.
 *
 * @param resource $conn   IMAP connection.
 * @param int      $uid    Message sequence number.
 * @param array    $report Report array (passed by reference).
 */
function indivisible_newsletter_diagnose_show_message($conn, int $uid, array &$report): void {
    $header_data = indivisible_newsletter_imap_fetch_section($conn, $uid, 'HEADER');
    if (empty($header_data)) {
        return;
    }
    $headers  = indivisible_newsletter_parse_headers($header_data);
    $report[] = '  Message #' . $uid . ':';
    $report[] = '    From: '    . ($headers['from']    ?? IN_LOG_NONE);
    $report[] = '    Subject: ' . indivisible_newsletter_decode_mime_header($headers['subject'] ?? IN_LOG_NONE);
    $report[] = '    Date: '    . ($headers['date']    ?? IN_LOG_NONE);

    $flags_result = indivisible_newsletter_imap_command($conn, 'FETCH ' . $uid . ' (FLAGS)');
    if (!is_wp_error($flags_result)) {
        foreach ($flags_result as $line) {
            if (preg_match('/FLAGS\s*\(([^)]*)\)/i', $line, $fm)) {
                $report[] = '    Flags: ' . $fm[1];
            }
        }
    }
    $report[] = '';
}

/**
 * Append recent-message headers section to $report.
 *
 * @param resource $conn      IMAP connection.
 * @param array    $all_uids  All message UIDs in the mailbox.
 * @param array    $report    Report array (passed by reference).
 */
function indivisible_newsletter_diagnose_recent_msgs($conn, array $all_uids, array &$report): void {
    $report[] = '--- Recent message headers (last 3) ---';
    if (empty($all_uids)) {
        return;
    }
    foreach (array_slice($all_uids, -3) as $uid) {
        indivisible_newsletter_diagnose_show_message($conn, $uid, $report);
    }
}

/**
 * Run diagnostics on the IMAP connection and search.
 *
 * Returns a detailed report of what the IMAP server returns at each step.
 *
 * @return string Diagnostic report.
 */
function indivisible_newsletter_diagnose() {
    $settings = indivisible_newsletter_get_settings();
    $report   = array();

    $report[] = '=== Newsletter Poster Diagnostics ===';
    $report[] = 'Host: ' . $settings['imap_host'] . ':' . $settings['imap_port'] . ' (' . $settings['imap_encryption'] . ')';
    $report[] = 'Username: ' . $settings['email_username'];
    $report[] = 'Folder: ' . $settings['imap_folder'];
    if (!empty($settings['filter_by_sender']) && !empty($settings['qualified_senders'])) {
        $senders_list = array_filter(array_map('trim', explode("\n", $settings['qualified_senders'])));
        $report[] = 'Filter by Sender: Enabled (' . implode(', ', $senders_list) . ')';
    } else {
        $report[] = 'Filter by Sender: Disabled (all emails processed)';
    }
    $report[] = '';

    if (empty($settings['imap_host']) || empty($settings['email_username']) || empty($settings['email_password'])) {
        $report[] = 'ERROR: Missing required settings.';
        return implode("\n", $report);
    }

    $password = indivisible_newsletter_decrypt($settings['email_password']);
    $report[] = 'Password decrypted: ' . (empty($password) ? 'EMPTY (decryption may have failed)' : 'OK (' . strlen($password) . ' chars)');
    $report[] = '';

    $report[] = '--- Connecting ---';
    $conn = indivisible_newsletter_imap_connect($settings, $password);
    if (is_wp_error($conn)) {
        $report[] = 'Connection FAILED: ' . $conn->get_error_message();
        return implode("\n", $report);
    }
    $report[] = 'Connected and authenticated successfully.';
    $report[] = '';

    $report[] = '--- Selecting mailbox: ' . $settings['imap_folder'] . ' ---';
    $select = indivisible_newsletter_imap_command($conn, IN_IMAP_SELECT_PREFIX . $settings['imap_folder'] . '"');
    if (is_wp_error($select)) {
        $report[] = 'SELECT FAILED: ' . $select->get_error_message();
        fclose($conn);
        return implode("\n", $report);
    }
    foreach ($select as $line) {
        $report[] = '  ' . trim($line);
    }
    $report[] = '';

    $diag_senders = array();
    if (!empty($settings['filter_by_sender']) && !empty($settings['qualified_senders'])) {
        $diag_senders = array_filter(array_map('trim', explode("\n", $settings['qualified_senders'])));
    }
    indivisible_newsletter_diagnose_sender_searches($conn, $diag_senders, $report);

    $report[] = '--- Searching: UNSEEN (all unseen) ---';
    indivisible_newsletter_diagnose_run_search($conn, IN_IMAP_SEARCH_UNSEEN, $report);
    $report[] = '';

    // Also try just FROM last sender (including read messages).
    $last_sender = !empty($diag_senders) ? (string) end($diag_senders) : '';
    $report[] = '--- Searching: FROM "' . $last_sender . '" (including read) ---';
    indivisible_newsletter_diagnose_run_search($conn, 'SEARCH FROM "' . $last_sender . '"', $report);
    $report[] = '';

    $report[] = '--- Searching: ALL ---';
    $all_result = indivisible_newsletter_imap_command($conn, 'SEARCH ALL');
    $all_uids   = array();
    if (!is_wp_error($all_result)) {
        $all_uids = indivisible_newsletter_imap_parse_search_uids($all_result);
        $report[] = '  Total messages: ' . count($all_uids);
    } else {
        $report[] = IN_LOG_SEARCH_FAILED . $all_result->get_error_message();
    }
    $report[] = '';

    indivisible_newsletter_diagnose_recent_msgs($conn, $all_uids, $report);

    $processed_ids = get_option(IN_PROCESSED_KEY, array());
    $report[]      = '--- Processed message IDs (' . count($processed_ids) . ') ---';
    foreach (array_slice($processed_ids, -5) as $id) {
        $report[] = '  ' . $id;
    }

    indivisible_newsletter_imap_command($conn, 'LOGOUT');
    fclose($conn);

    return implode("\n", $report);
}

/**
 * Decode MIME-encoded header values (RFC 2047).
 *
 * Handles =?charset?encoding?text?= format.
 *
 * @param string $text MIME-encoded header text.
 * @return string Decoded text.
 */
function indivisible_newsletter_decode_mime_header($text) {
    // Decode =?charset?Q?...?= and =?charset?B?...?= sequences.
    return preg_replace_callback(
        '/=\?([^?]+)\?([QB])\?([^?]*)\?=/i',
        function ($matches) {
            $charset  = $matches[1];
            $encoding = strtoupper($matches[2]);
            $text     = $matches[3];

            if ($encoding === 'B') {
                $decoded = base64_decode($text);
            } else {
                // Quoted-printable, but underscore = space in headers.
                $decoded = quoted_printable_decode(str_replace('_', ' ', $text));
            }

            // Convert to UTF-8 if needed.
            if (strtoupper($charset) !== 'UTF-8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $decoded);
                if ($converted !== false) {
                    $decoded = $converted;
                }
            }

            return $decoded;
        },
        $text
    );
}
