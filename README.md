# LLM Chat Interface

## Overview

NHLBI Chat is a chat front-end interface designed to provide staff with a secured, chat-like experience for their day-to-day work, through calls to Microsoft Azure's OpenAI API. This application mimics public tooling like ChatGPT and Anthropic's `Claude`, leveraging the Azure OpenAI API for basic chat functionalities and file uploads. 

## Features

- Basic chat interface similar to popular AI chat tools.
- File upload capability for document analysis. Accepted file formats include .pdf,.docx,.pptx,.txt,.md,.json, and .xml.
- Uses Azure OpenAI API for generating responses.
- High-quality speech playback for assistant replies routed through the internal Mocha TTS service, with chunked streaming so audio starts quickly even for long responses.
- Automatically promotes oversized pasted prompts into queued documents so the full text can be retrieved asynchronously without exhausting the chat context.

## Building and Launching

To build and host the LLM Chat Interface application:

1. Clone the repository to your local or server environment.
2. Ensure you have the necessary dependencies installed (as specified in the Code Dependencies section below).
3. Copy the `example_chat_config.ini` to your PHP include location. Note that we use a hard-coded config path, '/etc/apps/chat_config.ini'.
4. Configure the application settings in the `chat_config.ini` file.
5. Utilize the `chat_db.sql` to set up the database schema.
6. Follow deployment instructions to get the web server running (e.g., Apache, Nginx).

## Code Dependencies

The application relies on the following code dependencies:

- PHP: Your web server should have PHP installed and configured (we use PHP 8.0.30).

- MariaDB or MySQL: You need a database to store chat-related data (we use Server version: 10.5.22-MariaDB MariaDB Server).

- Python: The application uses Python for text extraction from various file formats (we use Python 3.9.18).

- Libraries for Python:
  - `docx` (for parsing .docx files)
  - `pdfminer` (for parsing .pdf files)
  - `python-pptx` (for parsing .pptx files)

- JavaScript Libraries: The application uses JavaScript libraries that are loaded from external sources:
  - Bootstrap
  - Highlight.js

## Azure API Configuration

For the Azure OpenAI API, you will need:

- An API key from Azure.
- The deployment name and API version you intend to use.
- The appropriate URL endpoint for API calls.
- Token and context limits as per your Azure plan.

## LDAP / Authentication Information

The application uses OpenID for authentication. You will need to configure:

- `authorization_url_base`, `clientId`, `client_secret`, and `callback` for OpenID.
- LDAP configurations for user authentication against your directory service.

## Database Information

The MariaDB schema for this chat interface is very simple, just two tables: `chat` and `exchange`. Note, the summary field is not in use at the moment. Additionally, the data is never deleted, we simply flag as deleted and hide in the interface. 

-- Table structure for table `chat`
--

CREATE TABLE `chat` (
  `id` varchar(32) NOT NULL,
  `user` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `deployment` varchar(64) DEFAULT NULL,
  `temperature` decimal(2,1) DEFAULT NULL,
  `document_name` varchar(255) DEFAULT NULL,
  `document_type` varchar(124) DEFAULT NULL,
  `document_text` longtext DEFAULT NULL,
  `new_title` tinyint(1) NOT NULL DEFAULT 1,
  `deleted` tinyint(4) DEFAULT 0,
  `sort_order` int(16) NOT NULL DEFAULT 0,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_viewed` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci

--
-- Table structure for table `exchange`
--

CREATE TABLE `exchange` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(32) NOT NULL,
  `user` varchar(255) DEFAULT NULL,
  `prompt` longtext DEFAULT NULL,
  `prompt_token_length` int(11) DEFAULT NULL,
  `reply` text DEFAULT NULL,
  `reply_token_length` int(11) DEFAULT NULL,
  `document_name` varchar(128) DEFAULT NULL,
  `document_type` varchar(124) DEFAULT NULL,
  `document_text` longtext DEFAULT NULL,
  `document_source` varchar(10) DEFAULT NULL,
  `image_gen_name` varchar(255) DEFAULT NULL,
  `deployment` varchar(64) DEFAULT NULL,
  `api_key` varchar(64) DEFAULT NULL,
  `temperature` decimal(2,1) DEFAULT NULL,
  `uri` varchar(256) DEFAULT NULL,
  `api_endpoint` varchar(255) DEFAULT NULL,
  `deleted` tinyint(4) DEFAULT 0,
  `timestamp` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_exchange_chat` (`chat_id`),
  CONSTRAINT `fk_exchange_chat` FOREIGN KEY (`chat_id`) REFERENCES `chat` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(32) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(124) NOT NULL,
  `content` longtext NOT NULL,
  `deleted` tinyint(1) DEFAULT 0,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_exchange_chat_docs` (`chat_id`),
  CONSTRAINT `fk_exchange_chat_docs` FOREIGN KEY (`chat_id`) REFERENCES `chat` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci

## Configuration Changes

Before launching the application, you need to make the following changes to the configuration files:

- Fill out the Azure API section with your specific API keys and deployment information.
- Update the OpenID and LDAP sections with your institution's authentication details.
- Customize the `[app]` section with your application's title, logo, and disclosure information.
- Ensure that the `[database]` section has the correct credentials for your database.

Note that the provided `example_chat_config.ini` serves as a template and must be filled with your specific details.

## Validation and Testing

The repository includes several repeatable checks that should run before deploying or promoting changes.

### Automated validation harness

- Install the Python test dependencies once: `pip install -r requirements-test.txt` (use a virtual environment where appropriate).
- If you do not want the integration tests to touch your primary chat schema, point the suite at an alternate config by exporting `CHAT_TEST_CONFIG_PATH=/etc/apps/chatdev_test_config.ini`.
- Execute the full validation bundle: `./scripts/run_validation.sh`.
  - Runs `php -l` across the codebase, PHP integration tests, Python bytecode compilation, parser/RAG unit tests, and linting.
  - Exports a summary to the console and exits non-zero on failure.

### RAG ingestion smoke test

- Queue and process a representative document end-to-end: `php scripts/validate_ingestion.php`.
- Optional flags:
  - `--document /path/to/file` to override the default fixture in `tests/fixtures/rag_ingestion_sample.txt`.
  - `--keep` to retain the queued document and generated vectors for inspection.
- The script exercises the worker loop, verifies `rag_index.ready = 1`, and cleans up temporary rows unless `--keep` is provided.

### Accessibility (Section 508) scan

1. Install prerequisites (once per host):
   - `sudo yum install -y nodejs jq`
   - `npm install -g pa11y`
   - `npx @puppeteer/browsers install chrome@stable --path ~/.cache/puppeteer` (Playwright and Pa11y share the cached Chromium build).
2. Start the PHP development server from the repo root: `php -S localhost:8080 -t .`.
3. Run the scan (reports are always saved under `logs/accessibility/`):
   - `VERBOSE_ACCESSIBILITY=1 ./scripts/validate_accessibility.sh`
   - Optional environment knobs:
     - `BASE_URL` (defaults to `http://localhost:8080`)
     - `APP_PATH` (defaults to `/chatdev`)
     - `ACCESSIBILITY_INCLUDE_WARNINGS=1` and/or `ACCESSIBILITY_INCLUDE_NOTICES=1` to retain lower-severity findings.
4. Review the generated `summary.md` alongside the per-page JSON exports and hand the bundle to NIH’s 508 review team as evidence of automated scans.

### UI smoke tests (Playwright)

The Playwright suite replaces the manual regression steps for authenticated chat flows.

1. One-time setup:
   - `npm install`
   - `npx playwright install --with-deps`
2. Ensure the local dev server is running with the development router (it mirrors the Apache rewrites and enables mocked completions):
   - `PLAYWRIGHT_FAKE_COMPLETIONS=1 APP_PATH=/chat php -S 127.0.0.1:18080 -t . scripts/dev_router.php`
3. Execute the tests:
   - `BASE_URL=http://127.0.0.1:18080 APP_PATH=/chat npm run test:ui`
   - The suite automatically mints a session via `scripts/create_dev_session.php`, posts a prompt, verifies the assistant response, and exercises the upload modal.
4. Playwright artifacts (traces, screenshots) land in `test-results/` or `playwright-report/` when a failure occurs, providing context for debugging.
5. If your database instance has not yet been migrated to include the `rag_index` table, the application will still load but document readiness will report as `false`; apply the latest DDL from `chat_db.sql` for full retrieval metadata.
6. When running locally, the suite also asserts image upload previews and verifies that prompt sanitization prevents script execution while leaving the new speech playback control unaffected.

### Accessibility/session helpers and client event logs

- The accessibility and UI scripts rely on `scripts/create_dev_session.php` to create a throwaway authenticated PHP session without touching NIH SSO.
- Client-side anomalies (such as the intermittent prompt submission failure) are captured in `logs/client_events.log`; tail this file during manual or automated runs to correlate front-end issues with server-side activity.

## Contribute

We welcome contributions, whether they are for bug fixes, feature additions, or improvements in documentation. For major changes, please open an issue first to discuss what you would like to change.

Please make sure to update tests as appropriate.

## License

This project is placed in the public domain, which means that it is free for anyone to use, modify, and distribute without any restrictions.
