// Coursepilot documentation site: copy buttons for Einstiegsprompts and the
// remembered language choice. Pages stay fully readable without JavaScript.
'use strict';

(function () {
  var LANG_KEY = 'coursepilot-docs-lang';

  function remember(lang) {
    try {
      localStorage.setItem(LANG_KEY, lang);
    } catch (e) {
      // Storage blocked: the choice simply is not remembered.
    }
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    var ok = document.execCommand('copy');
    document.body.removeChild(area);
    return ok ? Promise.resolve() : Promise.reject(new Error('copy failed'));
  }

  function setupCopyButtons() {
    var labels = document.documentElement.dataset;
    document.querySelectorAll('.prompt').forEach(function (box) {
      var code = box.querySelector('code');
      if (!code) {
        return;
      }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'copy-button';
      button.textContent = labels.copyLabel || 'Copy';
      button.addEventListener('click', function () {
        copyText(code.textContent).then(function () {
          button.textContent = labels.copiedLabel || 'Copied';
        }, function () {
          button.textContent = labels.copyFailedLabel || 'Copy failed';
        });
        setTimeout(function () {
          button.textContent = labels.copyLabel || 'Copy';
        }, 2000);
      });
      box.appendChild(button);
    });
  }

  function setupLanguageSwitch() {
    document.querySelectorAll('[data-lang-switch]').forEach(function (link) {
      link.addEventListener('click', function () {
        remember(link.getAttribute('hreflang'));
      });
    });
  }

  setupCopyButtons();
  setupLanguageSwitch();
})();
