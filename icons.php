<?php

function app_icon(string $name): string
{
    $shapes = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'apiaries' => '<path d="m12 3 9 5v8l-9 5-9-5V8z"/><path d="m3 8 9 5 9-5M12 13v8M7.5 5.5l9 5"/>',
        'hives' => '<path d="m4 7 8-4 8 4M5 8h14v5H5zM5 13h14v5H5zM3 21h18M7 18v3m10-3v3M10 10.5h4m-4 5h4"/>',
        'colonies' => '<ellipse cx="12" cy="14" rx="5" ry="7"/><path d="M7 12h10M7 16h10M10 7 8 3m6 4 2-4"/><path d="M7 10C1 4 0 14 7 15m10-5c6-6 7 4 0 5"/>',
        'inspections' => '<rect x="5" y="5" width="14" height="16" rx="2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m8 14 3 3 5-6"/>',
        'queens' => '<path d="m3 6 3 12h12l3-12-6 5-3-7-3 7zM6 21h12"/>',
        'groups' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/>',
        'users' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/>',
        'profile' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="10" cy="9" r="3"/><path d="M5 19a5 5 0 0 1 10 0m1-11h2m-2 4h2"/>',
        'logout' => '<path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4m6-13 5 5-5 5M9 12h11"/>',
        'collapse' => '<path d="m14 6-6 6 6 6"/>',
        'tasks' => '<path d="m3 6 2 2 3-4m3 2h10M3 13l2 2 3-4m3 2h10M3 20l2 2 3-4m3 2h10"/>',
        'activity' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($shapes[$name] ?? $shapes['dashboard']) . '</svg>';
}
