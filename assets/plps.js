(() => {
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

  const readCfg = (root) => {
    const ajaxUrl = root.getAttribute("data-plps-ajax-url") || "";
    const nonce = root.getAttribute("data-plps-nonce") || "";
    const limit = Number(root.getAttribute("data-plps-limit") || "8");
    const minChars = Number(root.getAttribute("data-plps-min-chars") || "2");

    return {
      ajaxUrl,
      nonce,
      limit: Number.isFinite(limit) ? limit : 8,
      minChars: Number.isFinite(minChars) ? minChars : 2,
    };
  };

  const initOne = (root) => {
    if (root.dataset.plpsInit === "1") return;
    root.dataset.plpsInit = "1";

    const cfg = readCfg(root);
    const input = root.querySelector(".plps__input");
    const results = root.querySelector(".plps__results");

    if (!cfg.ajaxUrl || !cfg.nonce) {
      setStatus(root, "Search config missing (likely caching/minify stripping).");
      return;
    }

    let activeIndex = -1;

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

      if (term.length < cfg.minChars) {
        setStatus(root, term.length ? `Type ${cfg.minChars - term.length} more character(s)…` : "");
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
          limit: String(cfg.limit),
        });

        if (!json || !json.success) {
          setStatus(root, "Search failed.");
          closeResults(root);
          return;
        }

        const items = (json.data && json.data.items) ? json.data.items : [];
        renderItems(root, items);
        setStatus(root, `${items.length} result(s).`);
        activeIndex = -1;
      } catch (e) {
        setStatus(root, "Search failed (network/server).");
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

  const initAll = () => {
    document.querySelectorAll("[data-plps]").forEach(initOne);
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }

  // Handle blocks injected later
  const mo = new MutationObserver(() => initAll());
  mo.observe(document.documentElement, { childList: true, subtree: true });
})();
