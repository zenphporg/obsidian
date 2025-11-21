const commitAnalyzerOptions = {
  preset: 'conventionalcommits',
  releaseRules: [
    { type: 'breaking', release: 'major' },
    { type: 'feat', release: 'minor' },
    { type: 'fix', release: 'patch' },
    { type: 'refactor', release: 'patch' },
    { type: 'docs', release: 'patch' },
    { type: 'wip', release: false },
    { type: 'chore', release: false },
    { scope: 'style', release: false },
    { scope: 'test', release: false },
  ],
  parserOpts: {
    noteKeywords: [],
  },
};

const releaseNotesGeneratorOptions = {
  writerOpts: {
    transform: (commit, context) => {
      const issues = [];

      // Create a mutable copy of the commit object
      const modifiedCommit = { ...commit };

      const types = {
        breaking: 'Breaking',
        feat: 'Features',
        fix: 'Bug Fixes',
        refactor: 'Code Refactoring',
        docs: 'Documentation',
        wip: 'Work in Progress',
        chore: 'Maintenance',
        style: 'Code Style Adjustments',
        test: 'Code Testing',
      };

      modifiedCommit.type = types[modifiedCommit.type];

      if (typeof modifiedCommit.hash === 'string') {
        modifiedCommit.shortHash = modifiedCommit.hash.substring(0, 7);
      }

      if (typeof modifiedCommit.subject === 'string') {
        let url = context.repository ? `${context.host}/${context.owner}/${context.repository}` : context.repoUrl;
        if (url) {
          url = `${url}/issues/`;
          // Issue URLs.
          modifiedCommit.subject = modifiedCommit.subject.replace(/#([0-9]+)/g, (_, issue) => {
            issues.push(issue);
            return `[#${issue}](${url}${issue})`;
          });
        }
        if (context.host) {
          // User URLs.
          modifiedCommit.subject = modifiedCommit.subject.replace(
            /\B@([a-z0-9](?:-?[a-z0-9/]){0,38})/g,
            (_, username) => {
              if (username.includes('/')) {
                return `@${username}`;
              }

              return `[@${username}](${context.host}/${username})`;
            }
          );
        }
      }

      // Create a new references array instead of modifying the original
      modifiedCommit.references = modifiedCommit.references.filter((reference) => {
        return !issues.includes(reference.issue);
      });

      return modifiedCommit;
    },
  },
};

module.exports = {
  debug: true,
  branches: ['+([0-9])?(.{+([0-9]),x}).x', 'main'],
  repositoryUrl: 'https://github.com/zenphporg/obsidian',

  plugins: [
    // analyze commits with conventional-changelog
    ['@semantic-release/commit-analyzer', commitAnalyzerOptions],
    // generate changelog content with conventional-changelog
    ['@semantic-release/release-notes-generator', releaseNotesGeneratorOptions],
    // updates the changelog file
    [
      '@semantic-release/changelog',
      {
        changelogFile: 'CHANGELOG.md',
        changelogTitle: '# Changelog',
      },
    ],
    // creating a new version commit
    [
      '@semantic-release/git',
      {
        assets: ['CHANGELOG.md'],
      },
    ],
    '@semantic-release/github',
  ],
};
