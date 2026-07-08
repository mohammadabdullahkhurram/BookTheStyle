<?php

/*
|--------------------------------------------------------------------------
| GoHighLevel integration
|--------------------------------------------------------------------------
| The single source for the scopes a salon's Private Integration Token must
| be granted to cover Phase 6 end to end: calendar + appointment sync,
| contact upserts, and team-member mapping. The connection card renders this
| list so users grant the right scopes when creating the token; a future
| validation check or docs page can reuse it.
*/

return [
    'required_scopes' => [
        'calendars.readonly',
        'calendars.write',
        'calendars/events.readonly',
        'calendars/events.write',
        'calendars/groups.readonly',
        'contacts.readonly',
        'contacts.write',
        'users.readonly',
    ],
];
