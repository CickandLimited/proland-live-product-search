(() => {
  const debounce = (fn, wait = 250) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), wait);
    };
  };

  const postJSON = async (url, data) => {
    const body = new URLSearchParams(data);
    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body,
      credentials: "same-origin",
    });
    return res.json();
  };

  const initOne = (root) => {
    if (root.dataset.plpsInit) return;
    root.dataset.plpsInit = "1";

    const input = root.querySelector(".plps__input");
    const results = root.querySelector(".plps__results");
    const status = root.querySelector(".plps__status");

    const cfg = {
      ajaxUrl: root.dataset.plpsAjaxUrl,
      nonce: root.dataset.plpsNonce,
      limit: Number(root.dataset.plpsLimit || 8),
      minChars: Number(root.dataset.plpsMinChars || 2),
    };

    const render = (items) => {
      results.innerHTML = "";

      if (!items.length) {
        results.innerHTML = `<div class="plps__empty">No results found</div>`;
        results.hidden = false;
        return;
      }

      items.forEach((item) => {
        const a = document.createElement("a");
        a.className = "plps__item";
        if (item.outOfStock) a.classList.add("is-out");

        a.href = item.url;

        a.innerHTML = `
          <div class="plps__itemTitle">${item.title}</div>
          <div class="plps__meta">
            <span><strong>Category:</strong> ${item.category}</span>
            <span><strong>Price:</strong> ${item.price}</span>
            <span class="plps__availability">
              <strong>Availability:</strong> ${item.availability}
            </span>
          </div>
        `;

        results.appendChild(a);
      });

      results.hidden = false;
    };

    const search = debounce(async () => {
      const term = input.value.trim();

      if (term.length < cfg.minChars) {
        results.hidden = true;
        status.textContent = "";
        return;
      }

      status.textContent = "Searching…";

      try {
        const json = await postJSON(cfg.ajaxUrl, {
          action: "plps_search_products",
          nonce: cfg.nonce,
          term,
          limit: cfg.limit,
        });

        if (!json.success) throw new Error();

        render(json.data.items || []);
        status.textContent = `${json.data.items.length} result(s)`;
      } catch {
        status.textContent = "Search failed";
        results.hidden = true;
      }
    }, 250);

    input.addEventListener("input", search);
  };

  const initAll = () => {
    document.querySelectorAll("[data-plps]").forEach(initOne);
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }

  new MutationObserver(initAll).observe(document.body, {
    childList: true,
    subtree: true,
  });
})();
