---
title: Salon onboarding + GHL & Voice AI setup
category: SOPs
order: 1
---

This is the standard operating procedure for bringing a new salon onto
BookTheStyle and wiring up its GoHighLevel sub-account and Voice AI. The
full SOP is being migrated here — this stub shows the structure.

## Overview

Onboarding runs in three passes:

1. Create the salon in the agency console and provision its owner.
2. Connect the GHL sub-account (Location ID, Calendar ID, Private Integration Token).
3. Map staff, verify the sync, and enable Voice AI + chat.

![Onboarding flow](/docs-assets/onboarding-flow.png)

## Prerequisites

| What | Where | Notes |
| --- | --- | --- |
| GHL sub-account | GHL agency view | One per salon |
| Calendar ID | GHL calendar settings | The service calendar the app mirrors |
| Integration token | GHL Private Integrations | Scoped, never shared between salons |

## Connecting GHL

Enter the connection details under the salon's Integrations tab. The token
is encrypted at rest; a test call verifies it before saving:

```text
Settings → Integrations → GoHighLevel
Location ID   →  from the sub-account URL
Calendar ID   →  from calendar settings
Token         →  Private Integration, api scopes only
```

## Voice AI

Voice AI books through the same booking API as the web widget — confirm a
test booking lands on the master calendar before handing over.

*(Full SOP content to follow — this doc is intentionally a stub.)*
