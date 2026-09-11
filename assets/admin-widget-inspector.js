(() => {
  const search = document.querySelector('[data-design-core-control-search]');
  const type = document.querySelector('[data-design-core-control-type]');
  const rows = Array.from(document.querySelectorAll('[data-design-core-control-row]'));
  const visible = document.querySelector('[data-design-core-control-visible]');
  const empty = document.querySelector('[data-design-core-control-empty]');

  if (!search || !type || !rows.length) {
    return;
  }

  const apply = () => {
    const query = search.value.trim().toLowerCase();
    const selectedType = type.value.trim().toLowerCase();
    let shown = 0;

    for (const row of rows) {
      const haystack = (row.dataset.controlSearch || '').toLowerCase();
      const rowType = (row.dataset.controlType || '').toLowerCase();
      const matchesQuery = !query || haystack.includes(query);
      const matchesType = !selectedType || rowType === selectedType;
      const shouldShow = matchesQuery && matchesType;
      row.hidden = !shouldShow;
      if (shouldShow) shown += 1;
    }

    if (visible) visible.textContent = String(shown);
    if (empty) empty.hidden = shown !== 0;
  };

  search.addEventListener('input', apply);
  type.addEventListener('change', apply);
})();
