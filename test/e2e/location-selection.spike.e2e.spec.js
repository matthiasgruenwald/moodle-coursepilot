'use strict';

const { test, expect } = require('@playwright/test');
const { config, hasBrowserCredentials, isSpikeProfile } = require('./helpers/env');
const { MoodlePage } = require('./helpers/moodle-page');
const { setupLocationFixture, cleanupLocationFixture } = require('./helpers/spike-location-fixture');

test.skip(!isSpikeProfile || !hasBrowserCredentials || config.courseId !== 6, 'Spike-Profil mit teacher_edit-Browserzugang fuer Kurs 6 erforderlich');

let fixture;

async function selectMoodle(page, target) {
  await page.locator(`a[data-target="${target}"]`).click();
  await page.locator(`[data-action="keep-moodle"][data-target="${target}"]`).click();
}

test.beforeAll(async () => {
  fixture = await setupLocationFixture(String(Date.now()));
});

test.afterAll(async () => {
  if (fixture) await cleanupLocationFixture(fixture);
});

test('Ortswahl: Moodle, WebDAV-Browsing, gefuellte Ordneruebergabe und Speicherausfall', async ({ page }) => {
  const moodle = new MoodlePage(page, config.moodleUrl, config);
  await moodle.login();
  await page.goto(`${config.moodleUrl.replace(/\/+$/, '')}/local/coursepilot/location_selection.php`, { waitUntil: 'networkidle' });

  await selectMoodle(page, 'context_area');
  await selectMoodle(page, 'material_store');
  await page.locator('#coursepilot-location-selection-finish').click();
  await expect(page.locator('.alert-success, .alert-info').first()).toBeVisible();

  await page.locator('[data-action="open-picker"][data-target="context_area"]').click();
  await expect(page.locator('#coursepilot-location-selection-modal')).toBeVisible();
  await page.locator(`[data-instance-id="${fixture.instanceid}"]`).click();
  await page.locator(`[data-folder-name="${fixture.filled}"]`).click();
  await expect(page.locator('#coursepilot-location-selection-breadcrumb')).toContainText(fixture.filled);
  await page.locator('#coursepilot-location-selection-confirmfolder').click();
  await expect(page.locator('#coursepilot-location-selection-confirm-modal')).toBeVisible();
  await page.locator('#coursepilot-location-selection-confirmfolder-ack').click();
  await selectMoodle(page, 'material_store');
  await page.locator('#coursepilot-location-selection-finish').click();
  await expect(page.locator('.alert-success').first()).toBeVisible();

  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('[data-action="open-picker"][data-target="context_area"]').click();
  await page.locator(`[data-instance-id="${fixture.badid}"]`).click();
  const error = page.locator('#coursepilot-location-selection-folders .alert');
  await expect(error).toBeVisible({ timeout: 15_000 });
  await expect(error).toContainText(/external storage|externe Speicher/i);
});
