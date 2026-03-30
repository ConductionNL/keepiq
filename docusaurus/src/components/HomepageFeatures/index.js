import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'End-to-End Encryption',
    description: (
      <>
        RSA-4096 encryption with a private Certificate Authority. Your secrets are encrypted at rest
        and only decryptable with your master password. Zero-knowledge architecture.
      </>
    ),
  },
  {
    title: 'Team Sharing & Applications',
    description: (
      <>
        Share secrets with Nextcloud users and groups. Register applications with CSR-based
        onboarding. Write-without-read enables secure credential provisioning.
      </>
    ),
  },
  {
    title: 'Nextcloud-Native',
    description: (
      <>
        Built on Nextcloud users, groups, notifications, and unified search. No external
        dependencies. Self-hosted, sovereign, and fully integrated with your collaboration platform.
      </>
    ),
  },
];

function Feature({title, description}) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
