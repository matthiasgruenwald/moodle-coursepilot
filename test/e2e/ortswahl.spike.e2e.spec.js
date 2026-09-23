'use strict';

const { test, expect } = require('@playwright/test');
const { config, hasBrowserCredentials, isSpikeProfile } = require('./helpers/env');
const { MoodlePage } = require('./helpers/moodle-page');
const { setupLocationFixture, cleanupLocationFixture } = require('./helpers/spike-location-fixture');

test.skip(!isSpikeProfile || !hasBrowserCredentials || config.courseId !== 6, 'Spike-Profil mit teacher_edit-Browserzugang fuer Kurs 6 erforderlich');

let fixture;

test.beforeAll(async () => {
  fixture = await setupLocationFixture(String(Date.now()));
});

test.afterAll(async () => {
  if (fixture) await cleanupLocationFixture(fixture);
});

test('Ortswahl: Moodle, WebDAV-Browsing, gefuellte Ordneruebergabe und Speicherausfall', async ({ page }) => {
  const moodle = new MoodlePage(page, config.moodleUrl, config);
  await moodle.login();
  await page.goto(`${config.moodleUrl.replace(/\/+$/, '')}/local/coursepilot/ortswahl.php`, { waitUntil: 'networkidle' });

  await page.locator('[data-action="keep-moodle"][data-target="kontextbereich"]').click();
  await page.locator('[data-action="keep-moodle"][data-target="materialbestand"]').click();
  await page.locator('#coursepilot-ortswahl-finish').click();
  await expect(page.locator('.alert-success, .alert-info').first()).toBeVisible();

  await page.locator('[data-action="open-picker"][data-target="kontextbereich"]').click();
  await expect(page.locator('#coursepilot-ortswahl-modal')).toBeVisible();
  await page.locator(`[data-instance-id="${fixture.instanceid}"]`).click();
  await page.locator(`[data-folder-name="${fixture.filled}"]`).click();
  await expect(page.locator('#coursepilot-ortswahl-breadcrumb')).toContainText(fixture.filled);
  await page.locator('#coursepilot-ortswahl-confirmfolder').click();
  await expect(page.locator('#coursepilot-ortswahl-confirm-modal')).toBeVisible();
  await page.locator('#coursepilot-ortswahl-confirmfolder-ack').click();
  await page.locator('[data-action="keep-moodle"][data-target="materialbestand"]').click();
  await page.locator('#coursepilot-ortswahl-finish').click();
  await expect(page.locator('.alert-success').first()).toBeVisible();

  await page.locator('[data-action="open-picker"][data-target="kontextbereich"]').click();
  await page.locator(`[data-instance-id="${fixture.badid}"]`).click();
  const error = page.locator('#coursepilot-ortswahl-folders .alert');
  await expect(error).toBeVisible();
  await expect(error).toContainText(/external storage|externe Speicher/i);
});
