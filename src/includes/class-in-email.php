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
    $select = indivisible_newsletter_imap_command($conn, 'SELECT "' . $settings['imap_folder'] . '"');
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
    $conn = indivisible_newsletter_imap_connect($settings, $password);

    if (is_wp_error($conn)) {
        return $conn;
    }

    // Select mailbox.
    $select = indivisible_newsletter_imap_command($conn, 'SELECT "' . $settings['imap_folder'] . '"');
    if (is_wp_error($select)) {
        fclose($conn);
        return $select;
    }

    // Search for messages. If filter_by_sender is enabled, search from each qualified sender.
    // We use the processed message IDs list (not UNSEEN flag) to avoid duplicates,
    // since messages may already be read in the mail client.
    $senders = array();
    if (!empty($settings['filter_by_sender']) && !empty($settings['qualified_senders'])) {
        $senders = array_filter(array_map('trim', explode("\n", $settings['qualified_senders'])));
    }

    $uids = array();
    if (!empty($senders)) {
        // Run a separate SEARCH for each sender and merge results.
        foreach ($senders as $sender) {
            $search_result = indivisible_newsletter_imap_command($conn, 'SEARCH FROM "' . $sender . '"');
            if (!is_wp_error($search_result)) {
                foreach ($search_result as $line) {
                    if (preg_match('/^\*\s+SEARCH\s+([\d\s]+)/i', $line, $m)) {
                        $uids = array_merge($uids, array_map('intval', preg_split('/\s+/', trim($m[1]))));
                    }
                }
            }
        }
        $uids = array_unique($uids);
        sort($uids);
    } else {
        $search_result = indivisible_newsletter_imap_command($conn, 'SEARCH ALL');
        if (is_wp_error($search_result)) {
            indivisible_newsletter_imap_command($conn, 'LOGOUT');
            fclose($conn);
            return array();
        }
        foreach ($search_result as $line) {
            if (preg_match('/^\*\s+SEARCH\s+([\d\s]+)/i', $line, $m)) {
                $uids = array_map('intval', preg_split('/\s+/', trim($m[1])));
            }
        }
    }

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
        error_log('Newsletter Poster: Processing message #' . $uid);

        // Fetch headers.
        $header_data = indivisible_newsletter_imap_fetch_section($conn, $uid, 'HEADER');
        if (empty($header_data)) {
            error_log('Newsletter Poster: Message #' . $uid . ' - header fetch returned empty');
            continue;
        }
        error_log('Newsletter Poster: Message #' . $uid . ' - headers fetched (' . strlen($header_data) . ' bytes)');

        $headers    = indivisible_newsletter_parse_headers($header_data);
        $message_id = $headers['message-id'] ?? '';

        error_log('Newsletter Poster: Message #' . $uid . ' - Message-ID: ' . $message_id);
        error_log('Newsletter Poster: Message #' . $uid . ' - Subject: ' . ($headers['subject'] ?? '(none)'));

        // Skip already-processed messages.
        if (!empty($message_id) && in_array($message_id, $processed_ids, true)) {
            error_log('Newsletter Poster: Message #' . $uid . ' - SKIPPED (already processed)');
            continue;
        }

        $subject = $headers['subject'] ?? 'Newsletter';
        // Decode MIME-encoded subject.
        $subject = indivisible_newsletter_decode_mime_header($subject);

        // Fetch the full message body to extract HTML.
        error_log('Newsletter Poster: Message #' . $uid . ' - fetching full body...');
        $body_data = indivisible_newsletter_imap_fetch_section($conn, $uid, '');
        error_log('Newsletter Poster: Message #' . $uid . ' - body fetched (' . strlen($body_data) . ' bytes)');

        $html = '';
        if (!empty($body_data)) {
            // Write body to temp file for debugging MIME structure.
            file_put_contents('/tmp/newsletter_debug_msg_' . $uid . '.txt', $body_data);
            error_log('Newsletter Poster: Message #' . $uid . ' - body saved to /tmp/newsletter_debug_msg_' . $uid . '.txt');
            $html = indivisible_newsletter_extract_html_from_raw($body_data);
            error_log('Newsletter Poster: Message #' . $uid . ' - HTML extracted (' . strlen($html) . ' bytes)');
        } else {
            error_log('Newsletter Poster: Message #' . $uid . ' - body fetch returned empty');
        }

        if (!empty($html)) {
            $emails[] = array(
                'message_id' => $message_id,
                'subject'    => $subject,
                'html'       => $html,
                'date'       => $headers['date'] ?? '',
                'uid'        => $uid,
            );
            error_log('Newsletter Poster: Message #' . $uid . ' - QUEUED for post creation');
        } else {
            error_log('Newsletter Poster: Message #' . $uid . ' - SKIPPED (no HTML content found)');
        }

        // Mark as read.
        indivisible_newsletter_imap_command($conn, 'STORE ' . $uid . ' +FLAGS (\\Seen)');
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

    $conn = @stream_socket_client($address, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
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
    static $tag_counter = 0;
    $tag_counter++;
    $tag = 'A' . str_pad($tag_counter, 4, '0', STR_PAD_LEFT);

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
 * Fetch a section of a message (headers or body).
 *
 * @param resource $conn    Socket connection.
 * @param int      $msg_num Message sequence number.
 * @param string   $section Section to fetch (e.g., 'HEADER', '', '1', '1.2').
 * @return string Fetched content or empty string.
 */
function indivisible_newsletter_imap_fetch_section($conn, $msg_num, $section) {
    static $fetch_tag = 1000;
    $fetch_tag++;
    $tag = 'F' . $fetch_tag;

    if ($section === 'HEADER') {
        $cmd = $tag . ' FETCH ' . $msg_num . ' BODY[HEADER]' . "\r\n";
    } elseif ($section === '') {
        $cmd = $tag . ' FETCH ' . $msg_num . ' BODY[]' . "\r\n";
    } else {
        $cmd = $tag . ' FETCH ' . $msg_num . ' BODY[' . $section . ']' . "\r\n";
    }

    fwrite($conn, $cmd);

    $data = '';
    $in_literal = false;
    $literal_size = 0;
    $literal_read = 0;
    $timeout = 60;
    $start = time();

    while (true) {
        if (time() - $start > $timeout) {
            break;
        }

        if ($in_literal && $literal_read < $literal_size) {
            // Read literal data.
            $remaining = $literal_size - $literal_read;
            $chunk = fread($conn, min($remaining, 8192));
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
            $literal_read += strlen($chunk);
            continue;
        }

        $line = fgets($conn, 8192);
        if ($line === false) {
            break;
        }

        // Check for literal size indicator {NNN}.
        if (!$in_literal && preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
            $literal_size = (int) $m[1];
            $literal_read = 0;
            $in_literal = true;
            continue;
        }

        // Check for tagged response.
        if (strpos($line, $tag . ' OK') === 0) {
            break;
        }
        if (strpos($line, $tag . ' NO') === 0 || strpos($line, $tag . ' BAD') === 0) {
            break;
        }

        // If we've finished reading the literal, remaining lines are closing paren etc.
        if ($in_literal && $literal_read >= $literal_size) {
            // Just consume until tagged response.
            continue;
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
 * Search multipart MIME body for HTML part.
 *
 * @param string $body     Message body.
 * @param string $boundary MIME boundary string.
 * @return string HTML content or empty string.
 */
function indivisible_newsletter_find_html_in_multipart($body, $boundary) {
    // Split by boundary.
    $parts = explode('--' . $boundary, $body);

    foreach ($parts as $part) {
        $part = ltrim($part, "\r\n");

        // Skip closing boundary and empty parts.
        if (empty($part) || strpos($part, '--') === 0) {
            continue;
        }

        // Split part headers from part body.
        $sections = preg_split('/\r?\n\r?\n/', $part, 2);
        if (count($sections) < 2) {
            continue;
        }

        $part_headers = $sections[0];
        $part_body    = $sections[1];

        // Remove trailing boundary markers.
        $part_body = preg_replace('/\r?\n--' . preg_quote($boundary, '/') . '--?\s*$/', '', $part_body);

        // Unfold continuation headers.
        $part_headers = preg_replace('/\r?\n[ \t]+/', ' ', $part_headers);

        // Get content type of this part.
        $part_ct = '';
        if (preg_match('/^Content-Type:\s*(.+)$/im', $part_headers, $m)) {
            $part_ct = trim($m[1]);
        }

        // If this part is HTML, return it.
        if (stripos($part_ct, 'text/html') !== false) {
            $encoding = '';
            if (preg_match('/^Content-Transfer-Encoding:\s*(\S+)/im', $part_headers, $m)) {
                $encoding = strtolower(trim($m[1]));
            }
            return indivisible_newsletter_decode_transfer_encoding($part_body, $encoding);
        }

        // If this part is multipart, recurse.
        if (preg_match('/boundary="?([^";\s]+)"?/i', $part_ct, $m)) {
            $result = indivisible_newsletter_find_html_in_multipart($part_body, $m[1]);
            if (!empty($result)) {
                return $result;
            }
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
 * Run diagnostics on the IMAP connection and search.
 *
 * Returns a detailed report of what the IMAP server returns at each step.
 *
 * @return string Diagnostic report.
 */
function indivisible_newsletter_diagnose() {
    $settings = indivisible_newsletter_get_settings();
    $report = array();

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

    // Connect.
    $report[] = '--- Connecting ---';
    $conn = indivisible_newsletter_imap_connect($settings, $password);
    if (is_wp_error($conn)) {
        $report[] = 'Connection FAILED: ' . $conn->get_error_message();
        return implode("\n", $report);
    }
    $report[] = 'Connected and authenticated successfully.';
    $report[] = '';

    // Select mailbox.
    $report[] = '--- Selecting mailbox: ' . $settings['imap_folder'] . ' ---';
    $select = indivisible_newsletter_imap_command($conn, 'SELECT "' . $settings['imap_folder'] . '"');
    if (is_wp_error($select)) {
        $report[] = 'SELECT FAILED: ' . $select->get_error_message();
        fclose($conn);
        return implode("\n", $report);
    }
    foreach ($select as $line) {
        $report[] = '  ' . trim($line);
    }
    $report[] = '';

    // Search UNSEEN, optionally filtered by sender(s).
    $diag_senders = array();
    if (!empty($settings['filter_by_sender']) && !empty($settings['qualified_senders'])) {
        $diag_senders = array_filter(array_map('trim', explode("\n", $settings['qualified_senders'])));
    }
    if (!empty($diag_senders)) {
        foreach ($diag_senders as $sender) {
            $report[] = '--- Searching: UNSEEN FROM "' . $sender . '" ---';
            $search_result = indivisible_newsletter_imap_command($conn, 'SEARCH UNSEEN FROM "' . $sender . '"');
            if (is_wp_error($search_result)) {
                $report[] = 'SEARCH FAILED: ' . $search_result->get_error_message();
            } else {
                foreach ($search_result as $line) {
                    $report[] = '  ' . trim($line);
                }
            }
        }
    } else {
        $report[] = '--- Searching: UNSEEN (sender filter disabled) ---';
        $search_result = indivisible_newsletter_imap_command($conn, 'SEARCH UNSEEN');
        if (is_wp_error($search_result)) {
            $report[] = 'SEARCH FAILED: ' . $search_result->get_error_message();
        } else {
            foreach ($search_result as $line) {
                $report[] = '  ' . trim($line);
            }
        }
    }
    $report[] = '';

    // Also try just UNSEEN to see what's there.
    $report[] = '--- Searching: UNSEEN (all unseen) ---';
    $unseen_result = indivisible_newsletter_imap_command($conn, 'SEARCH UNSEEN');
    if (is_wp_error($unseen_result)) {
        $report[] = 'SEARCH FAILED: ' . $unseen_result->get_error_message();
    } else {
        foreach ($unseen_result as $line) {
            $report[] = '  ' . trim($line);
        }
    }
    $report[] = '';

    // Also try just FROM sender (including read messages).
    $report[] = '--- Searching: FROM "' . $sender . '" (including read) ---';
    $from_result = indivisible_newsletter_imap_command($conn, 'SEARCH FROM "' . $sender . '"');
    if (is_wp_error($from_result)) {
        $report[] = 'SEARCH FAILED: ' . $from_result->get_error_message();
    } else {
        foreach ($from_result as $line) {
            $report[] = '  ' . trim($line);
        }
    }
    $report[] = '';

    // Search ALL to see total.
    $report[] = '--- Searching: ALL ---';
    $all_result = indivisible_newsletter_imap_command($conn, 'SEARCH ALL');
    if (is_wp_error($all_result)) {
        $report[] = 'SEARCH FAILED: ' . $all_result->get_error_message();
    } else {
        $all_uids = array();
        foreach ($all_result as $line) {
            if (preg_match('/^\*\s+SEARCH\s+([\d\s]+)/i', $line, $m)) {
                $all_uids = array_map('intval', preg_split('/\s+/', trim($m[1])));
            }
        }
        $report[] = '  Total messages: ' . count($all_uids);
    }
    $report[] = '';

    // Show headers of recent messages (last 3).
    $report[] = '--- Recent message headers (last 3) ---';
    if (!empty($all_uids)) {
        $recent = array_slice($all_uids, -3);
        foreach ($recent as $uid) {
            $header_data = indivisible_newsletter_imap_fetch_section($conn, $uid, 'HEADER');
            if (!empty($header_data)) {
                $headers = indivisible_newsletter_parse_headers($header_data);
                $report[] = '  Message #' . $uid . ':';
                $report[] = '    From: ' . ($headers['from'] ?? '(none)');
                $report[] = '    Subject: ' . indivisible_newsletter_decode_mime_header($headers['subject'] ?? '(none)');
                $report[] = '    Date: ' . ($headers['date'] ?? '(none)');
                // Check flags.
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
        }
    }

    // Show processed IDs.
    $processed_ids = get_option(IN_PROCESSED_KEY, array());
    $report[] = '--- Processed message IDs (' . count($processed_ids) . ') ---';
    if (!empty($processed_ids)) {
        foreach (array_slice($processed_ids, -5) as $id) {
            $report[] = '  ' . $id;
        }
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
        '/=\?([^?]+)\?(Q|B)\?([^?]*)\?=/i',
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
