<?php

declare(strict_types=1);

/*
 * Contact person shown on the public landing page for people who want help installing a
 * self-hosted copy. Set LANDING_CONTACT_WHATSAPP to an empty value to hide the section.
 * The number is published on the public page: only put a number whose owner agreed to that.
 */
return [
    'contact' => [
        'name' => env('LANDING_CONTACT_NAME', 'Kukuh TW'),
        // International format, digits only (no "+", spaces or leading 0), as wa.me expects.
        'whatsapp' => preg_replace('/\D+/', '', (string) env('LANDING_CONTACT_WHATSAPP', '628129893706')),
        'message' => env('LANDING_CONTACT_MESSAGE', 'Halo, saya tertarik memasang aplikasi pencatat keuangan self-hosted open source ini. Boleh minta informasi lebih lanjut?'),
    ],
];
