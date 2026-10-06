(function () {
  var body = document.body;
  var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  function pad(value) {
    return String(value).padStart(2, "0");
  }

  function replay(el, className) {
    el.classList.remove(className);
    void el.offsetWidth;
    el.classList.add(className);
  }

  /* ---------- Countdown ---------- */

  var card = document.querySelector("[data-countdown]");
  if (card) {
    var countdownKey = card.getAttribute("data-countdown-key") || "vote";
    var target = Number(card.getAttribute("data-countdown"));
    var serverNow = Number(card.getAttribute("data-server-now"));
    var offset = serverNow - Date.now();
    var units = {
      h: card.querySelector('[data-unit="h"]'),
      m: card.querySelector('[data-unit="m"]'),
      s: card.querySelector('[data-unit="s"]')
    };
    var title = card.querySelector("[data-countdown-title]");
    var button = document.getElementById("vote-now");
    var startedDisabled = button ? button.disabled : false;
    var reloadKey = "countdownReloaded:" + countdownKey;
    var shown = {};
    var titleText = title ? title.textContent : "";

    function setUnit(key, value) {
      var el = units[key];
      if (!el || shown[key] === value) {
        return;
      }
      var first = shown[key] === undefined;
      shown[key] = value;
      el.textContent = value;
      if (!first && !reduceMotion) {
        replay(el, "tick");
      }
    }

    function tick() {
      var remaining = target - (Date.now() + offset);
      if (remaining < 0) {
        remaining = 0;
      }
      var total = Math.floor(remaining / 1000);
      setUnit("h", pad(Math.floor(total / 3600)));
      setUnit("m", pad(Math.floor((total % 3600) / 60)));
      setUnit("s", pad(total % 60));
      if (remaining <= 0) {
        var reloadAtZero = card.getAttribute("data-reload-at-zero") === "1";
        if (reloadAtZero) {
          if (!sessionStorage.getItem(reloadKey)) {
            sessionStorage.setItem(reloadKey, "1");
            window.location.reload();
            return;
          }
        }
        if (button && button.disabled) {
          button.disabled = false;
          button.classList.add("is-live");
        }
        if (countdownKey === "vote" && !card.classList.contains("is-open")) {
          card.classList.add("is-open");
          if (title) {
            title.textContent = "Voting is open";
          }
        }
      } else if (startedDisabled && button) {
        button.disabled = true;
        button.classList.remove("is-live");
        if (card.classList.contains("is-open")) {
          card.classList.remove("is-open");
          if (title) {
            title.textContent = titleText;
          }
        }
        sessionStorage.removeItem(reloadKey);
      } else {
        sessionStorage.removeItem(reloadKey);
      }
    }

    function pullClock() {
      window.fetch("status.php", { cache: "no-store" })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            return;
          }
          if (countdownKey === "reg") {
            target = Number(data.regStart);
          } else {
            target = Number(data.voteStart);
          }
          offset = Number(data.serverNow) - Date.now();
          var current = body.getAttribute("data-phase");
          if (current && data.phase !== current) {
            if (countdownKey === "vote" && data.phase === "vote") {
              document.body.setAttribute("data-phase", "vote");
              tick();
              return;
            }
            window.location.reload();
            return;
          }
          tick();
        })
        .catch(function () {});
    }

    if (button && button.getAttribute("data-go")) {
      button.addEventListener("click", function () {
        if (!button.disabled) {
          window.location.href = button.getAttribute("data-go");
        }
      });
    }

    tick();
    window.setInterval(tick, 250);
    window.setInterval(pullClock, 1000);
    document.addEventListener("visibilitychange", function () {
      pullClock();
      tick();
    });
  }

  if (body.getAttribute("data-watch") === "1" && !card) {
    window.setInterval(function () {
      window.fetch("status.php", { cache: "no-store" })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          var current = body.getAttribute("data-phase");
          if (data && data.ok && current && data.phase !== current) {
            window.location.reload();
          }
        })
        .catch(function () {});
    }, 1000);
  }

  /* ---------- QR ---------- */

  var qr = document.getElementById("qrcode");
  if (qr && window.QRCode) {
    new window.QRCode(qr, {
      text: qr.getAttribute("data-url"),
      width: 220,
      height: 220,
      colorDark: "#000047",
      colorLight: "#ffffff",
      correctLevel: window.QRCode.CorrectLevel.M
    });
  }

  /* ---------- Forms ---------- */

  document.querySelectorAll("form[data-confirm-schedule]").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      var changed = false;
      form.querySelectorAll("input").forEach(function (input) {
        if (input.type === "hidden") {
          return;
        }
        if (input.value !== input.defaultValue) {
          changed = true;
        }
      });
      if (changed && !window.confirm(form.getAttribute("data-confirm-schedule"))) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll("form[data-confirm]").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      if (!window.confirm(form.getAttribute("data-confirm"))) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll("[data-code-input]").forEach(function (codeInput) {
    function cleanCode(value) {
      return value.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 6);
    }
    codeInput.addEventListener("paste", function (event) {
      var data = event.clipboardData || window.clipboardData;
      if (!data) {
        return;
      }
      event.preventDefault();
      codeInput.value = cleanCode(data.getData("text"));
    });
    codeInput.addEventListener("input", function () {
      codeInput.value = cleanCode(codeInput.value);
    });
  });

  document.querySelectorAll("form").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      if (event.defaultPrevented) {
        return;
      }
      var submit = event.submitter || form.querySelector('button[type="submit"]');
      if (!submit || submit.tagName !== "BUTTON") {
        return;
      }
      window.setTimeout(function () {
        submit.classList.add("is-loading");
        submit.disabled = true;
        submit.setAttribute("aria-busy", "true");
      }, 0);
    });
  });

  window.addEventListener("pageshow", function (event) {
    body.classList.remove("is-leaving");
    if (event.persisted) {
      document.querySelectorAll(".btn.is-loading").forEach(function (btn) {
        btn.classList.remove("is-loading");
        btn.disabled = false;
        btn.removeAttribute("aria-busy");
      });
    }
  });

  document.querySelectorAll("[data-pw-toggle]").forEach(function (toggle) {
    var input = toggle.parentElement ? toggle.parentElement.querySelector("input") : null;
    if (!input) {
      return;
    }
    toggle.addEventListener("click", function () {
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      toggle.setAttribute("aria-pressed", show ? "true" : "false");
      toggle.setAttribute("aria-label", show ? "Hide password" : "Show password");
      input.focus({ preventScroll: true });
    });
  });

  var auth = document.querySelector("[data-auth].is-error");
  if (auth) {
    var authInput = auth.querySelector('input[type="password"]');
    if (authInput) {
      authInput.addEventListener("input", function () {
        auth.classList.remove("is-error");
        authInput.removeAttribute("aria-invalid");
      }, { once: true });
    }
  }

  /* ---------- Toasts ---------- */

  function dismissToast(toast) {
    if (!toast || toast.classList.contains("is-leaving")) {
      return;
    }
    toast.classList.add("is-leaving");
    window.setTimeout(function () { toast.remove(); }, reduceMotion ? 0 : 280);
  }

  document.querySelectorAll(".toast").forEach(function (toast) {
    var close = toast.querySelector("[data-toast-close]");
    if (close) {
      close.addEventListener("click", function () { dismissToast(toast); });
    }
    var wait = toast.classList.contains("toast-error") ? 7000 : 4500;
    window.setTimeout(function () { dismissToast(toast); }, wait);
  });

  /* ---------- Page transitions ---------- */

  document.addEventListener("click", function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    var link = event.target.closest ? event.target.closest("a[href]") : null;
    if (!link || link.target || link.hasAttribute("download")) {
      return;
    }
    var url;
    try {
      url = new URL(link.href, window.location.href);
    } catch (e) {
      return;
    }
    if (url.origin !== window.location.origin || (url.hash && url.pathname === window.location.pathname)) {
      return;
    }
    if (reduceMotion) {
      return;
    }
    event.preventDefault();
    body.classList.add("is-leaving");
    window.setTimeout(function () { window.location.href = url.href; }, 170);
  });

  /* ---------- Live results ---------- */

  function animateNumber(el, to) {
    var from = Number(el.textContent) || 0;
    if (from === to) {
      return;
    }
    if (reduceMotion) {
      el.textContent = String(to);
      return;
    }
    var start = null;
    var duration = 600;
    function step(now) {
      if (start === null) {
        start = now;
      }
      var t = Math.min(1, (now - start) / duration);
      var eased = 1 - Math.pow(1 - t, 3);
      el.textContent = String(Math.round(from + (to - from) * eased));
      if (t < 1) {
        window.requestAnimationFrame(step);
      }
    }
    window.requestAnimationFrame(step);
    replay(el, "num-bump");
  }

  function patchBoard(board, next) {
    if (board.getAttribute("data-sig") !== next.getAttribute("data-sig")) {
      board.innerHTML = next.innerHTML;
      board.setAttribute("data-sig", next.getAttribute("data-sig"));
      board.classList.add("is-swapped");
      return;
    }
    var nums = board.querySelectorAll("[data-num]");
    var nextNums = next.querySelectorAll("[data-num]");
    nums.forEach(function (el, i) {
      if (nextNums[i]) {
        animateNumber(el, Number(nextNums[i].textContent) || 0);
      }
    });
    var pcts = board.querySelectorAll("[data-pct]");
    var nextPcts = next.querySelectorAll("[data-pct]");
    pcts.forEach(function (el, i) {
      if (nextPcts[i]) {
        el.textContent = nextPcts[i].textContent;
      }
    });
    var meters = board.querySelectorAll("[data-meter]");
    var nextMeters = next.querySelectorAll("[data-meter]");
    meters.forEach(function (el, i) {
      if (nextMeters[i]) {
        el.style.setProperty("--w", nextMeters[i].style.getPropertyValue("--w"));
      }
    });
    var status = board.querySelector(".board-status");
    var nextStatus = next.querySelector(".board-status");
    if (status && nextStatus && status.innerHTML !== nextStatus.innerHTML) {
      status.className = nextStatus.className;
      status.innerHTML = nextStatus.innerHTML;
    }
  }

  var liveSeconds = Number(body.getAttribute("data-live-results") || "0");
  if (liveSeconds > 0 && window.DOMParser) {
    var refreshing = false;
    window.setInterval(function () {
      if (refreshing || document.hidden) {
        return;
      }
      refreshing = true;
      window.fetch(window.location.href, { cache: "no-store", credentials: "same-origin" })
        .then(function (response) {
          if (response.redirected || !response.ok) {
            window.location.reload();
            return null;
          }
          return response.text();
        })
        .then(function (html) {
          if (!html) {
            return;
          }
          var doc = new window.DOMParser().parseFromString(html, "text/html");
          if (doc.body.getAttribute("data-phase") !== body.getAttribute("data-phase")) {
            window.location.reload();
            return;
          }
          var total = document.querySelector("[data-total-votes]");
          var nextTotal = doc.querySelector("[data-total-votes]");
          if (total && nextTotal) {
            animateNumber(total, Number(nextTotal.textContent) || 0);
            var label = document.querySelector("[data-total-label]");
            var nextLabel = doc.querySelector("[data-total-label]");
            if (label && nextLabel) {
              label.textContent = nextLabel.textContent;
            }
          }
          document.querySelectorAll("[data-board]").forEach(function (board) {
            var next = doc.querySelector('[data-board="' + board.getAttribute("data-board") + '"]');
            if (next) {
              patchBoard(board, next);
            }
          });
        })
        .catch(function () {})
        .then(function () { refreshing = false; });
    }, liveSeconds * 1000);
  }

  var refreshSeconds = Number(body.getAttribute("data-refresh") || "0");
  if (refreshSeconds > 0) {
    window.setTimeout(function () {
      window.location.reload();
    }, refreshSeconds * 1000);
  }

  /* ---------- Vote confirmation ---------- */

  var celebrate = document.querySelector("[data-celebrate]");
  if (celebrate && !reduceMotion) {
    var colors = ["#ed5d21", "#ff7a3d", "#ff9a66", "#ffd2b8", "#ffffff", "#2b33c8"];
    var layer = document.createElement("div");
    layer.className = "confetti";
    layer.setAttribute("aria-hidden", "true");
    for (var i = 0; i < 42; i++) {
      var piece = document.createElement("i");
      var angle = Math.random() * Math.PI * 2;
      var distance = 120 + Math.random() * 260;
      piece.style.setProperty("--x", Math.round(Math.cos(angle) * distance) + "px");
      piece.style.setProperty("--y", Math.round(Math.sin(angle) * distance * 0.7 + 180 + Math.random() * 160) + "px");
      piece.style.setProperty("--r", Math.round(Math.random() * 720 - 360) + "deg");
      piece.style.setProperty("--d", Math.round(1100 + Math.random() * 700) + "ms");
      piece.style.setProperty("--delay", Math.round(500 + Math.random() * 180) + "ms");
      piece.style.setProperty("--c", colors[i % colors.length]);
      if (i % 3 === 0) {
        piece.style.width = "6px";
        piece.style.height = "6px";
        piece.style.borderRadius = "50%";
      }
      layer.appendChild(piece);
    }
    body.appendChild(layer);
    window.setTimeout(function () { layer.remove(); }, 2800);
  }
})();
