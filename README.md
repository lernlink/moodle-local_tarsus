# Tarsus connector for Moodle (local_tarsus)

The connector between a Moodle site and Tarsus, the AI
learning coach built on the Flexudy Brain. It holds the credentials, indexes
course material into the brain, keeps that index in step with the course, and
guards every outbound call. It has no learner-facing UI of its own: the other
Tarsus plugins build on it.

| Plugin | Role | Repository |
|---|---|---|
| **local_tarsus** (this plugin) | Connector: credentials, indexing, guardrails | moodle-local_tarsus |
| mod_tarsus | The learning activity (ask, test, build knowledge) | moodle-mod_tarsus |
| block_tarsus | Course block with the learner's standing and a teacher summary | moodle-block_tarsus |
| report_tarsus | Course report (early stage) | moodle-report_tarsus |

Install the connector first; the other plugins declare a dependency on it.

## What it does

- **Credentials and brain.** API key, brain id and base URL live in the
  site settings. A *Tarsus brains* page lists every brain the key can reach,
  with its state, so an administrator can pick the right one.
- **Material indexing.** Pages, files and URLs that a teacher includes in a
  Tarsus activity are sent to the brain as resources. Edits re-index, deletions
  untrack, and a *Tarsus deletions* page shows anything the brain has not yet
  confirmed removed, with a retry.
- **Guardrails** on every call, in this order: a kill switch, a circuit
  breaker with capped backoff, a site-wide credit budget per day, and a rate
  limit per minute. A usage ledger records credits per context and user.
- **Assistant identity.** Name, description, avatar and voice of the
  assistant that learners talk to.
- **Pseudonymous learners.** The brain never receives names or e-mail
  addresses. Each learner is identified by a per-site HMAC pseudonym.
- **Mock mode** serves every call from a deterministic mock, for development
  and for trying the plugins without a Tarsus account.
- **Privacy API.** Metadata, export and deletion for the usage ledger and
  the pseudonyms.

## Requirements

- Moodle 5.0 or later, tested on 5.2 and 5.3.
- PHP 8.2 or later.
- A Tarsus account with an API key and a brain, unless you only use mock mode.

## Installation

1. Unzip into `local/tarsus` (Moodle 5.1+: `public/local/tarsus`), or add the
   repository as a Git submodule at that path.
2. Visit *Site administration › Notifications* to install.
3. Open *Site administration › Plugins › Local plugins › Tarsus* and enter the
   API key and base URL, then choose the brain on the *Tarsus brains* page.
4. Install mod_tarsus, then block_tarsus and report_tarsus as needed.

## Settings

| Setting | Meaning |
|---|---|
| API key, Brain id, Base URL | The connection. One brain per site. |
| Assistant name, id, description, avatar | How the assistant appears in the chat. |
| Voice mode, Voice | The voice that reads learning units aloud. |
| Mock mode | Serve all calls from the built-in mock. |
| Kill switch | Block every outbound call at once; the queue holds. |
| Rate limit | Maximum calls per minute (token bucket). |
| Credit budget | Maximum AI credits per day before calls are refused. |

## Data sent to Tarsus

The text and files of included course material, the learners' questions and
answers, the activity's title and settings, and a pseudonymous learner id.
Check your data processing agreement with Tarsus before enabling the plugins
on a production site.

## Development

Unit tests run against the mock client:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_tarsus_testsuite
```

The GitHub workflow runs moodle-plugin-ci (phplint, phpcs, phpdoc, mustache,
grunt, PHPUnit, Behat) on Moodle 5.2 and 5.3 with PostgreSQL and MariaDB.

## Credits

Developed by Vadym Nersesov, lern.link GmbH. Tarsus and the Flexudy Brain are
products of Tarsus GmbH.

## License

GNU GPL v3 or later: https://www.gnu.org/licenses/
