/**
 * Doriath landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the OpenRegister
 * landing page at openregister.conduction.nl (docs/src/pages/index.js).
 *
 * Doriath is in development — this page tells visitors what the app
 * is going to be (self-hosted password and secrets vault, per-user,
 * per-team, audited) and previews the three surfaces the UI will
 * land with. No <AppMock> yet (no doriath variant in the preset);
 * the widget panels are token-built sketches.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude — likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
} from '@conduction/docusaurus-preset/components';

/* Padlock glyph — the body of the doriath identity, lifted straight
   from the apps-catalog entry on conduction-website. Stroke-only so it
   inherits hero `iconColor` without any fill override. */
const DORIATH_ICON = (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M8 10V7a4 4 0 0 1 8 0v3" />
    <rect x="4" y="10" width="16" height="11" rx="2" />
  </svg>
);

const TAGLINE = (
  <>
    Password en secrets management op je eigen Nextcloud. Voor jezelf,
    voor je team, met een volledig audit trail — wie heeft welk
    secret aangemaakt, gedeeld, gebruikt, en wanneer. Geen externe
    vault, geen extra leverancier. In ontwikkeling.
  </>
);

/* --- Token-built mock widget panels -----------------------------------
   Sketches of the three surfaces Doriath will land with: a personal
   vault, items shared with a team, and a recent-activity feed. All
   token-only so they restyle automatically with the brand palette. */

function MyVaultPanel() {
  const rows = [
    { tone: 'var(--c-cobalt-300)', label: 'GH · admin' },
    { tone: 'var(--c-lavender-300)', label: 'AWS · prod' },
    { tone: 'var(--c-mint-500)', label: 'SMTP · relay' },
    { tone: 'var(--c-forest-300)', label: 'Stripe · live' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 0',
            borderBottom:
              i < rows.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <span
            style={{
              width: 12,
              height: 14,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 9,
              color: 'var(--c-cobalt-700)',
            }}
          >
            {row.label}
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              color: 'var(--c-cobalt-500)',
            }}
          >
            ••••••••
          </div>
        </div>
      ))}
    </div>
  );
}

function SharedWithTeamPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', who: 'platform-ops', n: 12 },
    { tone: 'var(--c-cobalt-300)', who: 'engineering', n: 28 },
    { tone: 'var(--c-lavender-300)', who: 'sales', n: 6 },
    { tone: 'var(--c-orange-knvb)', who: 'finance', n: 4 },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 9,
              color: 'var(--c-cobalt-700)',
            }}
          >
            {row.who}
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              fontWeight: 700,
              color: 'var(--c-cobalt-500)',
            }}
          >
            {row.n} secrets
          </div>
        </div>
      ))}
    </div>
  );
}

function RecentActivityPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', what: 'rotated', when: '2m ago' },
    { tone: 'var(--c-cobalt-300)', what: 'viewed', when: '14m ago' },
    { tone: 'var(--c-lavender-300)', what: 'shared', when: '1h ago' },
    { tone: 'var(--c-orange-knvb)', what: 'created', when: 'today' },
    { tone: 'var(--c-forest-300)', what: 'revoked', when: 'yesterday' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 10,
              height: 12,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 9,
              color: 'var(--c-cobalt-700)',
            }}
          >
            {row.what}
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              color: 'var(--c-cobalt-500)',
            }}
          >
            {row.when}
          </div>
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'My vault',
    desc: 'Persoonlijke wachtwoorden en API-keys, encrypted op de Nextcloud-server. Per item: een naam, een waarde, een notitie, en wie het mag zien.',
    panel: <MyVaultPanel />,
  },
  {
    title: 'Shared with team',
    desc: 'Secrets per Nextcloud-groep. Een nieuwe medewerker krijgt automatisch toegang tot wat de groep deelt; iemand die vertrekt verliest het in één klik.',
    panel: <SharedWithTeamPanel />,
  },
  {
    title: 'Recent activity',
    desc: 'Wie heeft welk secret aangemaakt, geopend, gedeeld, geroteerd, of ingetrokken — met tijdstempel. Auditeerbaar, ook achteraf.',
    panel: <RecentActivityPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="Doriath"
      description="Self-hosted password and secrets vault for Nextcloud. Per-user, per-team, audited. In development."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          status={{ label: 'In development', color: 'var(--c-orange-knvb)' }}
          version="pre-release"
          locales="NL · EN"
          title="Doriath"
          tagline={TAGLINE}
          primaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/doriath',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          iconColor="var(--c-orange-knvb)"
          icon={DORIATH_ICON}
        />

        <WidgetShelf
          eyebrow="What Doriath will land with"
          title="Eén plek voor wachtwoorden en secrets — op je eigen Nextcloud."
          lede="Een password manager mag niet wéér een extra leverancier zijn. Doriath leeft binnen je bestaande Nextcloud: dezelfde users, dezelfde groepen, dezelfde audit log. Per-user vaults, gedeelde team-secrets, en een activity feed die zegt wie wat heeft gedaan."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
