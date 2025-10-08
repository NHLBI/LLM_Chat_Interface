// @ts-check
const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const path = require('path');

const APP_PATH = process.env.APP_PATH ?? '';
const BASE_URL = process.env.BASE_URL ?? 'http://127.0.0.1:8080';
const IMAGE_BASE64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/wIAAgMBApNqvwAAAABJRU5ErkJggg==';

function createSessionCookie() {
  const scriptPath = path.resolve(__dirname, '../../scripts/create_dev_session.php');
  const result = spawnSync('php', [scriptPath], { encoding: 'utf8' });

  if (result.error) {
    throw result.error;
  }
  if (result.status !== 0) {
    throw new Error(`create_dev_session.php exited with code ${result.status}: ${result.stderr}`);
  }

  const output = result.stdout.trim();
  const match = output.match(/([^=]+)=([^\s]+)/);
  if (!match) {
    throw new Error(`Unable to parse session cookie from output: ${output}`);
  }
  return { name: match[1], value: match[2] };
}

async function newAuthenticatedPage(browser) {
  const { name, value } = createSessionCookie();
  const context = await browser.newContext({
    baseURL: BASE_URL,
    viewport: { width: 1280, height: 720 },
  });

  const domain = new URL(BASE_URL).hostname;
  await context.addCookies([
    {
      name,
      value,
      domain,
      path: '/',
      httpOnly: true,
      sameSite: 'Lax',
    },
  ]);

  const page = await context.newPage();
  return { context, page };
}

function buildTarget(pathname) {
  const normalizedBase = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const baseUrl = new URL(normalizedBase);

  let prefix = (APP_PATH ?? '').trim();
  if (prefix.startsWith('/')) {
    prefix = prefix.slice(1);
  }
  if (prefix !== '' && !prefix.endsWith('/')) {
    prefix += '/';
  }

  const relative = `${prefix}${pathname.replace(/^\//, '')}`;
  return new URL(relative, baseUrl).toString();
}

async function navigateToChat(page) {
  const target = buildTarget('index.php');
  const response = await page.goto(target, { waitUntil: 'domcontentloaded' });

  if (!response) {
    throw new Error(`No response returned when navigating to ${target}. Is the PHP dev server running?`);
  }

  const status = response.status();
  if (status >= 400) {
    const body = await response.text();
    const snippet = body.slice(0, 400);
    throw new Error(`Navigation to ${target} failed with status ${status}. Response preview:\n${snippet}`);
  }

  const splashVisible = await page
    .locator('a[href*="auth_redirect.php"]')
    .first()
    .isVisible({ timeout: 1000 })
    .catch(() => false);

  if (splashVisible) {
    throw new Error(
      `Navigation to ${target} returned the splash/login screen. ` +
        `Ensure scripts/create_dev_session.php can mint a session cookie and that it is being injected.`
    );
  }

  await page.waitForSelector('#messageForm', { timeout: 20000 });
}

function extractChatIdFromUrl(urlString) {
  if (!urlString) {
    return null;
  }
  try {
    const parsed = new URL(urlString);
    const parts = parsed.pathname.split('/').filter(Boolean);
    if (parts.length === 0) {
      return null;
    }
    const candidate = parts[parts.length - 1];
    if (/^[0-9a-fA-F]{16,64}$/.test(candidate)) {
      return candidate;
    }
  } catch (err) {
    // ignore parse errors; treat as no chat id
  }
  return null;
}

async function waitForChatNavigation(page, newChatId) {
  const base = BASE_URL.endsWith('/') ? BASE_URL.slice(0, -1) : BASE_URL;
  let appSegment = (APP_PATH ?? '').trim();
  if (appSegment === '/' || appSegment === '') {
    appSegment = '';
  } else {
    appSegment = appSegment.replace(/^\//, '').replace(/\/$/, '');
    appSegment = `/${appSegment}`;
  }

  if (!newChatId) {
    await page.waitForSelector('#messageForm', { timeout: 20000 });
    return;
  }

  const expectedUrl = `${base}${appSegment}/${newChatId}`;
  await page.waitForURL(expectedUrl, { timeout: 20000 });
  await page.waitForSelector('#messageForm', { timeout: 20000 });
}

async function cleanupChat(page) {
  try {
    const match = page.url().match(/\/chat\/([0-9A-Fa-f]+)/);
    if (!match) {
      return;
    }
    const chatId = match[1];
    await page.evaluate(async (id) => {
      const params = new URLSearchParams();
      params.set('chat_id', id);
      await fetch('delete_chat.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: params.toString(),
      });
    }, chatId);
  } catch (err) {
    console.warn('cleanupChat warning:', err);
  }
}

 test.describe('Authenticated chat flows', () => {
  test('user can submit a prompt and receive a reply', async ({ browser }, testInfo) => {
    testInfo.setTimeout(120000);
    const { context, page } = await newAuthenticatedPage(browser);

    try {
      await navigateToChat(page);

      const message = `Automated accessibility smoke ${Date.now()}`;
      await page.fill('#userMessage', message);

      const submitButton = page.locator('#messageForm button[type="submit"]');
      const responsePromise = page.waitForResponse((response) => {
        return response.url().includes('ajax_handler.php') && response.request().method() === 'POST';
      });
      const navigationPromise = page
        .waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 })
        .catch(() => null);

      await submitButton.click();
      const postResponse = await responsePromise;
      const navigation = await navigationPromise;

      expect(postResponse.status(), 'ajax_handler.php should return HTTP 200').toBe(200);

      let newChatId = extractChatIdFromUrl(navigation?.url());
      if (!newChatId) {
        newChatId = extractChatIdFromUrl(page.url());
      }
      await waitForChatNavigation(page, newChatId);

      await page.waitForResponse(
        (response) =>
          response.url().includes('get_messages.php') &&
          response.request().method() === 'GET',
        { timeout: 20000 }
      );

      const userMessageLocator = page.locator('.user-message').last();
      await userMessageLocator.waitFor({ timeout: 20000 });
      await expect(userMessageLocator).toContainText(message, { timeout: 15000 });

      const assistantLocator = page.locator('.assistant-message').last();
      await assistantLocator.waitFor({ timeout: 60000 });
      const assistantText = await assistantLocator.innerText();
      expect(assistantText.trim().length).toBeGreaterThan(0);
    } finally {
      await cleanupChat(page);
      await context.close();
    }
  });

  test('user can open the upload modal', async ({ browser }, testInfo) => {
    const { context, page } = await newAuthenticatedPage(browser);
    try {
      await navigateToChat(page);
      const uploadButton = page.locator('button.upload-button');
      await uploadButton.click();
      await expect(page.locator('#uploadModal')).toBeVisible({ timeout: 10000 });
    } finally {
      await context.close();
    }
  });

  test('user can preview and upload an image document', async ({ browser }) => {
    const { context, page } = await newAuthenticatedPage(browser);
    try {
      await navigateToChat(page);
      await page.locator('button.upload-button').click();
      await expect(page.locator('#uploadModal')).toBeVisible({ timeout: 10000 });

      const imageBuffer = Buffer.from(IMAGE_BASE64, 'base64');
      await page.setInputFiles('input#fileInput', {
        name: 'preview-test.png',
        mimeType: 'image/png',
        buffer: imageBuffer,
      });

      await expect(page.locator('#preview')).toContainText('preview-test.png', { timeout: 5000 });

      const [uploadResponse] = await Promise.all([
        page.waitForResponse(
          (response) => response.url().includes('upload.php') && response.request().method() === 'POST'
        ),
        page.click('button:has-text("Upload")'),
      ]);

      expect(uploadResponse.ok()).toBeTruthy();
      await waitForChatNavigation(page, null);

      const chatMatch = page.url().match(/\/chat\/([0-9A-Fa-f]+)/);
      expect(chatMatch).toBeTruthy();
      const chatId = chatMatch ? chatMatch[1] : null;

      const docs = await page.evaluate(async ({ chatId }) => {
        if (!chatId) {
          return [];
        }
        const params = new URLSearchParams();
        params.set('chat_id', chatId);
        params.set('images_only', 'false');
        const response = await fetch(`get_uploaded_images.php?${params.toString()}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        return response.ok ? await response.json() : [];
      }, { chatId });

      const uploadedDoc = Array.isArray(docs)
        ? docs.find((doc) => doc.document_name === 'preview-test.png')
        : null;
      expect(uploadedDoc).toBeTruthy();
      expect(uploadedDoc?.document_type).toContain('image/png');
    } finally {
      await cleanupChat(page);
      await context.close();
    }
  });

  test('user input is sanitized before rendering', async ({ browser }) => {
    const { context, page } = await newAuthenticatedPage(browser);
    try {
      await navigateToChat(page);
      const payload = '<img src=x onerror="window.__pwned = true">';
      await page.fill('#userMessage', payload);

      const responsePromise = page.waitForResponse(
        (response) => response.url().includes('ajax_handler.php') && response.request().method() === 'POST'
      );
      const navigationPromise = page
        .waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 })
        .catch(() => null);

      await page.locator('#messageForm button[type="submit"]').click();
      const ajaxResponse = await responsePromise;
      expect(ajaxResponse.ok()).toBeTruthy();

      await navigationPromise;
      await waitForChatNavigation(page, null);

      const innerHTML = await page.locator('.user-message').last().innerHTML();
      expect(innerHTML).not.toContain('<script');

      const wasExecuted = await page.evaluate(() => window.__pwned === true);
      expect(wasExecuted).toBeFalsy();
    } finally {
      await cleanupChat(page);
      await context.close();
    }
  });
});
