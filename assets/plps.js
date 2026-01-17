(() => {
  const cfg = window.PLPS || {};

  // If config didn't get injected for any reason, fail gracefully (no console fatal)
  if (!cfg.ajaxUrl || !cfg.nonce) {
    // Optional: uncomment if you want a hint in console
    // console.warn("[PLPS] Missing config (ajaxUrl/nonce). Inline script may be blocked by optimisation plugin.");
    return;
  }

  const debounce = (fn, wait = 250) => {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), wait);
    };
  };

  const postJSON = async (url, data) => {
    const body = new URLSearchParams();
    Object.entries(data).forEach(([k, v]) => body.append(k, v));

    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body,
      credentials: "same-origin",
    });

    return res.json();
  };

  const closeResults = (root) => {
    const results = root.querySelector(".plps__results");
    const input = root.querySelector(".plps__input");
    results.hidden = true;
    results.innerHTML = "";
    input.setAttribute("aria-expanded", "false");
  };

  const openResults = (root) => {
    const results = root.querySelector(".plps__results");
    const input = root.querySelector(".plps__input");
    results.hidden = false;
    input.setAttribute("aria-expanded", "true");
  };

  const setStatus = (root, text) => {
    const status = root.querySelector(".plps__status");
    status.textContent = text || "";
  };

  const setLoading = (root, isLoading) => {
    root.classList.toggle("is-loading", !!isLoading);
  };

  const renderItems = (root, items) => {
    const results = root.querySelector(".plps__results");
    results.innerHTML = "";

    if (!items || !items.length) {
      results.innerHTML = `<div class="plps__empty">No results found.</div>`;
      openResults(root);
      return;
    }

    const frag = document.createDocumentFragment();

    items.forEach((item, idx) => {
      const a = document.createElement("a");
      a.className = "plps__item";
      a.href = item.url;
      a.setAttribute("role", "option");
      a.setAttribute("tabindex", "-1");
      a.dataset.index = String(idx);

      const title = document.createElement("div");
      title.className = "plps__itemTitle";
      title.textContent = item.title || "";

      const snippet = document.createElement("div");
      snippet.className = "plps__itemSnippet";
      snippet.textContent = item.snippet || "";

      a.appendChild(title);
      if (item.snippet) a.appendChild(snippet);

      frag.appendChild(a);
    });

    results.appendChild(frag);
    openResults(root);
  };

  const initOne = (root) => {
    const input = root.querySelector(".plps__input");
    const results = root.querySelector(".plps__results");

    let activeIndex = -1;
    const minChars = Number(cfg.minChars ?? 2);
    const limit = Number(cfg.limit ?? 8);

    const focusItem = (idx) => {
      const items = results.querySelectorAll(".plps__item");
      items.forEach((el) => el.classList.remove("is-active"));
      if (idx >= 0 && idx < items.length) {
        items[idx].classList.add("is-active");
        items[idx].focus();
        activeIndex = idx;
      } else {
        activeIndex = -1;
        input.focus();
      }
    };

    const doSearch = debounce(async () => {
      const term = (input.value || "").trim();

      if (term.length < minChars) {
        setStatus(root, term.length ? `Type ${minChars - term.length} more character(s)…` : "");
        closeResults(root);
        return;
      }

      setLoading(root, true);
      setStatus(root, "Searching…");

      try {
        const json = await postJSON(cfg.ajaxUrl, {
          action: "plps_search_products",
          nonce: cfg.nonce,
          term,
          limit: String(limit),
        });

        if (!json || !json.success) {
          setStatus(root, "Search failed.");
          closeResults(root);
          return;
        }

        renderItems(root, json.data.items || []);
        setStatus(root, `${(json.data.items || []).length} result(s).`);
        activeIndex = -1;
      } catch (e) {
        setStatus(root, "Search failed.");
        closeResults(root);
      } finally {
        setLoading(root, false);
      }
    }, 250);

    input.addEventListener("input", () => doSearch());

    input.addEventListener("keydown", (e) => {
      const items = results.querySelectorAll(".plps__item");
      const isOpen = !results.hidden;

      if (e.key === "Escape") {
        closeResults(root);
        return;
      }

      if (!isOpen || !items.length) return;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        focusItem(Math.min(activeIndex + 1, items.length - 1));
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        focusItem(Math.max(activeIndex - 1, -1));
      } else if (e.key === "Enter") {
        if (activeIndex >= 0 && activeIndex < items.length) {
          e.preventDefault();
          items[activeIndex].click();
        }
      }
    });

    document.addEventListener("click", (e) => {
      if (!root.contains(e.target)) closeResults(root);
    });

    input.addEventListener("focus", () => {
      if (results.innerHTML.trim() !== "" && results.hidden) openResults(root);
    });
  };

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-plps]").forEach(initOne);
  });
})();
