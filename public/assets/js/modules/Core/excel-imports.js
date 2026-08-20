(() => {
  document.querySelectorAll('[data-excel-import-form]').forEach((form) => {
    form.addEventListener('submit', () => {
      form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = true;
      });
    }, { once: true });
  });
})();
