import React from 'react';
import ComponentCreator from '@docusaurus/ComponentCreator';

export default [
  {
    path: '/features/',
    component: ComponentCreator('/features/', '1d2'),
    exact: true
  },
  {
    path: '/docs/',
    component: ComponentCreator('/docs/', '7e4'),
    routes: [
      {
        path: '/docs/',
        component: ComponentCreator('/docs/', '305'),
        routes: [
          {
            path: '/docs/',
            component: ComponentCreator('/docs/', '940'),
            routes: [
              {
                path: '/docs/ARCHITECTURE/',
                component: ComponentCreator('/docs/ARCHITECTURE/', '34e'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/category/admin-guide/',
                component: ComponentCreator('/docs/category/admin-guide/', 'bc9'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/category/tutorials/',
                component: ComponentCreator('/docs/category/tutorials/', 'ae9'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/category/user-guide/',
                component: ComponentCreator('/docs/category/user-guide/', 'a58'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/DESIGN-REFERENCES/',
                component: ComponentCreator('/docs/DESIGN-REFERENCES/', '7fb'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/FEATURES/',
                component: ComponentCreator('/docs/FEATURES/', 'fcf'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/intro/',
                component: ComponentCreator('/docs/intro/', '224'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/admin/admin-settings/',
                component: ComponentCreator('/docs/tutorials/admin/admin-settings/', '944'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/user/first-launch/',
                component: ComponentCreator('/docs/tutorials/user/first-launch/', '391'),
                exact: true,
                sidebar: "tutorialSidebar"
              }
            ]
          }
        ]
      }
    ]
  },
  {
    path: '/',
    component: ComponentCreator('/', '2e1'),
    exact: true
  },
  {
    path: '*',
    component: ComponentCreator('*'),
  },
];
