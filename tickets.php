<?php
declare(strict_types=1);
require_once __DIR__.'/includes/coming-soon.php';
tg_coming_soon([
    'title' => 'Support Tickets',
    'lead' => 'Submit and track payment inquiries, bank UTR delays and API requests.',
    'done' => ['Order and UTR history to reference in a ticket', 'Status page for platform incidents'],
    'next' => ['Ticket threads with attachments (max 5)', 'Topics: Payment/UTR, IMAP, API/Webhook, General', 'Awaiting-reply and resolved states'],
]);
