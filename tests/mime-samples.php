<?php
declare(strict_types=1);
/**
 * Receipt-mail fixtures for the MIME tests.
 *
 * These mirror the shapes providers actually send: plain text, quoted-printable,
 * base64 with a non-UTF-8 charset, and the common Gmail shape where the readable
 * amount lives in the HTML alternative and a tracking pixel sits in the same tree.
 * Amounts/UTRs are arbitrary — no real customer data.
 */

return [
    // 1. Simple plain-text receipt.
    'plain' => "From: alerts@bank.example\r\n"
        ."To: receipts@example.com\r\n"
        ."Subject: Payment received\r\n"
        ."Message-ID: <abc123@bank.example>\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 7bit\r\n"
        ."\r\n"
        ."Rs.499.00 has been credited to your account.\r\n"
        ."UTR: 123456789012\r\n"
        ."Paid by: ASHA DEVI\r\n",

    // 2. Quoted-printable HTML with the rupee entity, comments and a pixel.
    'quoted_printable_html' => "From: alerts@upi.example\r\n"
        ."Subject: =?UTF-8?B?UGF5bWVudCByZWNlaXZlZA==?=\r\n"
        ."Message-ID: <qp1@upi.example>\r\n"
        ."Content-Type: multipart/alternative; boundary=\"b1\"\r\n"
        ."\r\n"
        ."--b1\r\n"
        ."Content-Type: text/html; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: quoted-printable\r\n"
        ."\r\n"
        ."<html><body><p><b>&#8377;1,249.50</b> received</p>\r\n"
        ."<p>UPI ref no: 987654321098</p>\r\n"
        ."<p>From: RAVI KUMAR</p>\r\n"
        ."<img src=3D\"https://cdn.example/pixel.gif\" width=3D\"1\">\r\n"
        ."</body></html>\r\n"
        ."--b1--\r\n",

    // 3. Base64 body with a Latin-1 charset and a folded header.
    'base64_latin1' => "From: alerts@bank.example\r\n"
        ."Subject: Credit alert\r\n"
        ."Message-ID: <b64@bank.example>\r\n"
        ."Content-Type: text/plain;\r\n"
        ." charset=ISO-8859-1\r\n"
        ."Content-Transfer-Encoding: base64\r\n"
        ."\r\n"
        .base64_encode("INR 250.00 credited. UTR NO: 111122223333. Paid by: JOSE")."\r\n",

    // 4. Gmail-shaped forward: mixed -> alternative (plain empty, HTML real) + attachment.
    'gmail_forward' => "From: receipts@example.com\r\n"
        ."Subject: Fwd: You received money\r\n"
        ."Message-ID: <fwd99@example.com>\r\n"
        ."Content-Type: multipart/mixed; boundary=\"mix\"\r\n"
        ."\r\n"
        ."--mix\r\n"
        ."Content-Type: multipart/alternative; boundary=\"alt\"\r\n"
        ."\r\n"
        ."--alt\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 7bit\r\n"
        ."\r\n"
        ."--alt\r\n"
        ."Content-Type: text/html; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: base64\r\n"
        ."\r\n"
        .base64_encode("<div><b>&#8377;75.00</b> received from PRIYA<br>UTR 444455556666</div>")."\r\n"
        ."--alt--\r\n"
        ."--mix\r\n"
        ."Content-Type: application/pdf; name=\"receipt.pdf\"\r\n"
        ."Content-Disposition: attachment; filename=\"receipt.pdf\"\r\n"
        ."Content-Transfer-Encoding: base64\r\n"
        ."\r\n"
        .base64_encode('PDF-1.4 fake attachment UTR 999999999999 Rs.1.00')."\r\n"
        ."--mix--\r\n",

    // 5. No amount at all: an unrelated notification that must not match.
    'unrelated' => "From: news@example.com\r\n"
        ."Subject: Weekly newsletter\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."\r\n"
        ."Thanks for reading. No payment here.\r\n",
];
