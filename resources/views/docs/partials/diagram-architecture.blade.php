{{-- System overview: GHL owns the conversation, BTS owns the calendar.
     Hand-authored inline SVG in the Marble palette — crisp at any size,
     no JS, no external renderer. --}}
<svg viewBox="0 0 760 400" role="img" aria-label="System overview: GoHighLevel handles conversation and CRM; BookTheStyle is the booking engine; they connect via Custom Actions and tag-gated contact sync" style="min-width:640px;width:100%;height:auto;font-family:inherit;">
    <defs>
        <marker id="dg-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
            <path d="M 0 1 L 9 5 L 0 9 z" fill="#824C71" />
        </marker>
    </defs>

    {{-- Entry actors --}}
    <rect x="55" y="16" width="150" height="34" rx="17" fill="#FFFFFF" stroke="#D8D3CB" />
    <text x="130" y="38" text-anchor="middle" font-size="12.5" fill="#2B2A28">Caller / lead</text>
    <rect x="545" y="16" width="170" height="34" rx="17" fill="#FFFFFF" stroke="#D8D3CB" />
    <text x="630" y="38" text-anchor="middle" font-size="12.5" fill="#2B2A28">Salon website visitor</text>

    <line x1="130" y1="50" x2="130" y2="88" stroke="#824C71" stroke-width="1.5" marker-end="url(#dg-arrow)" />
    <text x="140" y="72" font-size="10.5" fill="#6B6862">phone / SMS</text>
    <line x1="630" y1="50" x2="630" y2="176" stroke="#824C71" stroke-width="1.5" marker-end="url(#dg-arrow)" />
    <text x="638" y="72" font-size="10.5" fill="#6B6862">books via widget</text>

    {{-- GHL subsystem --}}
    <rect x="30" y="90" width="320" height="288" rx="12" fill="#F7F5F2" stroke="#D8D3CB" />
    <text x="46" y="114" font-size="11" font-weight="600" letter-spacing="0.6" fill="#6B6862">GOHIGHLEVEL (LOOPFLO) — CONVERSATION &amp; CRM</text>

    <rect x="52" y="132" width="180" height="36" rx="8" fill="#FFFFFF" stroke="#C9A5BC" />
    <text x="142" y="155" text-anchor="middle" font-size="12.5" fill="#2B2A28">Voice AI agent</text>
    <rect x="52" y="184" width="180" height="36" rx="8" fill="#FFFFFF" stroke="#C9A5BC" />
    <text x="142" y="207" text-anchor="middle" font-size="12.5" fill="#2B2A28">Workflows</text>
    <rect x="52" y="292" width="180" height="52" rx="8" fill="#FFFFFF" stroke="#D8D3CB" />
    <text x="142" y="314" text-anchor="middle" font-size="12.5" fill="#2B2A28">Contacts + tags</text>
    <text x="142" y="330" text-anchor="middle" font-size="10.5" fill="#6B6862">SMS / email / voice out</text>

    {{-- BTS subsystem --}}
    <rect x="410" y="90" width="320" height="288" rx="12" fill="#F7F5F2" stroke="#D8D3CB" />
    <text x="426" y="114" font-size="11" font-weight="600" letter-spacing="0.6" fill="#6B6862">BOOKTHESTYLE — BOOKING ENGINE</text>

    <rect x="432" y="132" width="180" height="36" rx="8" fill="#FFFFFF" stroke="#C9A5BC" />
    <text x="522" y="155" text-anchor="middle" font-size="12.5" fill="#2B2A28">Booking API</text>
    <rect x="432" y="184" width="180" height="36" rx="8" fill="#FFFFFF" stroke="#C9A5BC" />
    <text x="522" y="207" text-anchor="middle" font-size="12.5" fill="#2B2A28">Booking widget</text>
    <rect x="432" y="292" width="276" height="52" rx="8" fill="#FFFFFF" stroke="#D8D3CB" />
    <text x="570" y="314" text-anchor="middle" font-size="12.5" fill="#2B2A28">Salons · staff · services · availability</text>
    <text x="570" y="330" text-anchor="middle" font-size="10.5" fill="#6B6862">appointments — the single source of truth</text>

    {{-- Custom Action calls: GHL → BTS --}}
    <line x1="232" y1="150" x2="430" y2="150" stroke="#824C71" stroke-width="1.5" marker-end="url(#dg-arrow)" />
    <text x="331" y="142" text-anchor="middle" font-size="10.5" fill="#824C71">Custom Action: availability / book</text>
    <line x1="232" y1="202" x2="430" y2="202" stroke="#824C71" stroke-width="1.5" marker-end="url(#dg-arrow)" />
    <text x="331" y="194" text-anchor="middle" font-size="10.5" fill="#824C71">Custom Action</text>

    {{-- Internal flows into the datastore --}}
    <line x1="522" y1="168" x2="522" y2="182" stroke="#9C968D" stroke-width="1.2" marker-end="url(#dg-arrow)" />
    <line x1="522" y1="220" x2="522" y2="290" stroke="#9C968D" stroke-width="1.2" marker-end="url(#dg-arrow)" />

    {{-- Contact sync: bidirectional, tag-gated --}}
    <line x1="234" y1="318" x2="430" y2="318" stroke="#824C71" stroke-width="1.5" stroke-dasharray="5 4" marker-end="url(#dg-arrow)" marker-start="url(#dg-arrow)" />
    <text x="331" y="310" text-anchor="middle" font-size="10.5" fill="#824C71">contact sync — tag-gated</text>
</svg>
