# Web Chat 1

## Overview

Web Chat 1 is a chat front-end interface designed to provide NHLBI staff with a secured, chat-like experience for their day-to-day work, through calls to Microsoft Azure's OpenAI API. This application mimics public tooling like ChatGPT and Google Bard, leveraging the Azure OpenAI API for basic chat functionalities and file uploads. It operates within an NHLBI STRIDES environment to ensure secure and compliant use of generative AI technologies.

## Features

- Basic chat interface similar to popular AI chat tools.
- File upload capability for document analysis. Accepted file formats include .pdf,.docx,.pptx,.txt,.md,.json, and .xml.
- Uses Azure OpenAI API for generating responses.
- Designed to meet NIH OCIO AI Cybersecurity Guidance.

## Building and Launching

To build and host the Web Chat 1 application:

1. Clone the repository to your local or server environment.
2. Ensure you have the necessary dependencies installed (as specified in a separate dependencies document or section).
3. Copy the `example_chat_config.ini` to your PHP include location. Note that we use the include path, '/etc/apps/chat_config.ini'.
4. Configure the application settings in the `chat_config.ini` file.
5. Utilize the `osi_chat.sql` to set up the database schema.
6. Follow deployment instructions to get the web server running (e.g., Apache, Nginx).

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
  `title` varchar(128) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `deployment` varchar(64) DEFAULT NULL,
  `document_name` varchar(128) DEFAULT NULL,
  `document_text` longtext DEFAULT NULL,
  `deleted` tinyint(4) DEFAULT 0,
  `timestamp` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `exchange`
--

CREATE TABLE `exchange` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(32) NOT NULL,
  `user` varchar(255) DEFAULT NULL,
  `prompt` text DEFAULT NULL,
  `reply` text DEFAULT NULL,
  `deployment` varchar(64) DEFAULT NULL,
  `deleted` tinyint(4) DEFAULT 0,
  `timestamp` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_exchange_chat` (`chat_id`),
  CONSTRAINT `fk_exchange_chat` FOREIGN KEY (`chat_id`) REFERENCES `chat` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;p

## Configuration Changes

Before launching the application, you need to make the following changes to the configuration files:

- Fill out the Azure API section with your specific API keys and deployment information.
- Update the OpenID and LDAP sections with your institution's authentication details.
- Customize the `[app]` section with your application's title, logo, and disclosure information.
- Ensure that the `[database]` section has the correct credentials for your database.

Note that the provided `example_chat_config.ini` serves as a template and must be filled with your specific details.

## Out-of-the-box Limitations

The application is configured for use within the NHLBI STRIDES environment and may require adjustments for:

- Different Azure API plans or versions.
- Alternative authentication methods if not using OpenID or LDAP.
- Institutional policies on data handling and AI usage.

## Additional Information

- **PII Policy**: Adhere strictly to the policy of not sharing Personally Identifiable Information (PII).
- **Decision-Making Policy**: Do not base critical decision-making or policymaking solely on data from AI services.
- **Feedback**: Feedback on the tool is encouraged to improve functionality and user experience.

## Contribute

We welcome contributions, whether they are for bug fixes, feature additions, or improvements in documentation. For major changes, please open an issue first to discuss what you would like to change.

Please make sure to update tests as appropriate.

## License

[Specify License Here]


