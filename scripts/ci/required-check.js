'use strict';

// Every mandatory job must succeed, including jobs omitted or skipped by Actions.
try {
  const needs = JSON.parse(process.env.NEEDS_JSON);
  const required = ['quality', 'phpunit', 'js-native', 'artifact'];
  const failed = required.filter(job => needs[job]?.result !== 'success');
  for (const job of required) {
    console.log(`${job}: ${needs[job]?.result || 'missing'}`);
  }
  if (failed.length > 0) {
    console.error(`::error::Pflichtjobs nicht erfolgreich: ${failed.join(', ')}`);
    process.exitCode = 1;
  }
} catch (error) {
  console.error(`::error::Pflichtjob-Ergebnisse ungueltig: ${error.message}`);
  process.exitCode = 1;
}
