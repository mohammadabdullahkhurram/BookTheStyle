<?php

/*
|--------------------------------------------------------------------------
| GoHighLevel integration
|--------------------------------------------------------------------------
| The single source for the scopes a salon's Private Integration Token must
| be granted to cover Phase 6 end to end: calendar + appointment sync,
| contact upserts, and team-member mapping. Keyed scope => human label,
| matching how GHL's own scope picker names them, so users can find and
| tick the exact entries. The connection card renders this list; a future
| validation check or docs page can reuse it.
|
| Exact GHL v2 scope strings — note the slash form for the events and
| groups scopes. users.write is deliberately absent (sensitive + unused).
*/

return [

    /*
    | Inbound contact webhooks are TAG-GATED: an unknown GHL contact only
    | becomes an app client when it carries this tag (case-insensitive).
    | Updates to already-matched clients apply regardless of tags. Keeps
    | GHL's lead/form-fill firehose out of the Clients directory.
    */
    'client_tag' => env('GHL_CLIENT_TAG', 'client'),

    'required_scopes' => [
        'calendars.readonly' => 'View calendars',
        'calendars.write' => 'Edit calendars',
        'calendars/events.readonly' => 'View calendar events (appointments)',
        'calendars/events.write' => 'Edit calendar events (create/update/cancel appointments)',
        'calendars/groups.readonly' => 'View calendar groups',
        'contacts.readonly' => 'View contacts',
        'contacts.write' => 'Edit contacts',
        'users.readonly' => 'View users (team members)',
    ],
];
