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
        var redirectTo = card.getAttribute("data-redirect");
        if (redirectTo) {
          window.location.href = redirectTo;
          return;
        }
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
          } else if (countdownKey === "end") {
            target = Number(data.voteEnd);
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
            if (countdownKey === "end" && data.phase === "ended") {
              document.body.setAttribute("data-phase", "ended");
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
    var qrUrl = qr.getAttribute("data-url") || "";
    new window.QRCode(qr, {
      text: qrUrl,
      width: 220,
      height: 220,
      colorDark: "#000047",
      colorLight: "#ffffff",
      correctLevel: window.QRCode.CorrectLevel.M
    });

    function drawLines(ctx, text, x, y, maxWidth, lineHeight) {
      var line = "";
      var lines = [];
      var i;
      for (i = 0; i < text.length; i++) {
        var next = line + text.charAt(i);
        if (ctx.measureText(next).width > maxWidth && line) {
          lines.push(line);
          line = text.charAt(i);
        } else {
          line = next;
        }
      }
      if (line) {
        lines.push(line);
      }
      lines.forEach(function (row, index) {
        ctx.fillText(row, x, y + index * lineHeight);
      });
    }

    function buildPoster(url) {
      var holder = document.createElement("div");
      holder.setAttribute("aria-hidden", "true");
      holder.style.cssText = "position:fixed;left:-10000px;top:0;";
      document.body.appendChild(holder);
      new window.QRCode(holder, {
        text: url,
        width: 900,
        height: 900,
        colorDark: "#000047",
        colorLight: "#ffffff",
        correctLevel: window.QRCode.CorrectLevel.H
      });
      var source = holder.querySelector("canvas");
      var canvas = document.createElement("canvas");
      canvas.width = 1240;
      canvas.height = 1754;
      var ctx = canvas.getContext("2d");
      ctx.fillStyle = "#ffffff";
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.textAlign = "center";
      ctx.fillStyle = "#ed5d21";
      ctx.font = "800 42px 'Plus Jakarta Sans', sans-serif";
      ctx.fillText("FEMFI", canvas.width / 2, 170);
      ctx.fillStyle = "#000047";
      ctx.font = "400 88px 'Archivo Black', sans-serif";
      ctx.fillText("BEST DRESS", canvas.width / 2, 280);
      ctx.fillStyle = "#615f80";
      ctx.font = "600 34px 'Plus Jakarta Sans', sans-serif";
      ctx.fillText("Scan to register and vote", canvas.width / 2, 350);
      var box = 920;
      var x = (canvas.width - box) / 2;
      var y = 430;
      ctx.drawImage(source, x, y, box, box);
      ctx.fillStyle = "#000047";
      ctx.font = "700 30px 'Plus Jakarta Sans', sans-serif";
      drawLines(ctx, url, canvas.width / 2, y + box + 90, canvas.width - 160, 42);
      holder.remove();
      return canvas;
    }

    function saveBlob(blob, filename) {
      var link = document.createElement("a");
      var href = URL.createObjectURL(blob);
      link.href = href;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(function () { URL.revokeObjectURL(href); }, 1500);
    }

    function buildPdf(jpegBytes, pixelW, pixelH) {
      var pageW = 595.28;
      var pageH = 841.89;
      var margin = 28;
      var scale = Math.min((pageW - margin * 2) / pixelW, (pageH - margin * 2) / pixelH);
      var drawW = pixelW * scale;
      var drawH = pixelH * scale;
      var x = (pageW - drawW) / 2;
      var y = (pageH - drawH) / 2;
      var content = "q\n" + drawW.toFixed(2) + " 0 0 " + drawH.toFixed(2) + " " + x.toFixed(2) + " " + y.toFixed(2) + " cm\n/Im0 Do\nQ\n";
      var encoder = new TextEncoder();
      var chunks = [];
      var offsets = [];
      function pushStr(value) { chunks.push(encoder.encode(value)); }
      function pushBytes(value) { chunks.push(value); }
      function lengthSoFar() {
        var total = 0;
        chunks.forEach(function (chunk) { total += chunk.length; });
        return total;
      }
      pushStr("%PDF-1.4\n");
      offsets[1] = lengthSoFar();
      pushStr("1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");
      offsets[2] = lengthSoFar();
      pushStr("2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n");
      offsets[3] = lengthSoFar();
      pushStr("3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " + pageW.toFixed(2) + " " + pageH.toFixed(2) + "] /Contents 4 0 R /Resources << /XObject << /Im0 5 0 R >> >> >>\nendobj\n");
      var contentBytes = encoder.encode(content);
      offsets[4] = lengthSoFar();
      pushStr("4 0 obj\n<< /Length " + contentBytes.length + " >>\nstream\n");
      pushBytes(contentBytes);
      pushStr("\nendstream\nendobj\n");
      offsets[5] = lengthSoFar();
      pushStr("5 0 obj\n<< /Type /XObject /Subtype /Image /Width " + pixelW + " /Height " + pixelH + " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " + jpegBytes.length + " >>\nstream\n");
      pushBytes(jpegBytes);
      pushStr("\nendstream\nendobj\n");
      var xrefAt = lengthSoFar();
      var xref = "xref\n0 6\n0000000000 65535 f \n";
      var n;
      for (n = 1; n <= 5; n++) {
        xref += String(offsets[n]).padStart(10, "0") + " 00000 n \n";
      }
      xref += "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" + xrefAt + "\n%%EOF";
      pushStr(xref);
      return new Blob(chunks, { type: "application/pdf" });
    }

    function dataUrlBytes(dataUrl) {
      var binary = atob(dataUrl.split(",")[1]);
      var bytes = new Uint8Array(binary.length);
      var i;
      for (i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
      }
      return bytes;
    }

    function printPoster(canvas) {
      var jpeg = dataUrlBytes(canvas.toDataURL("image/jpeg", 0.95));
      var pdf = buildPdf(jpeg, canvas.width, canvas.height);
      var href = URL.createObjectURL(pdf);
      var frame = document.createElement("iframe");
      frame.setAttribute("aria-hidden", "true");
      frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0;";
      frame.src = href;
      document.body.appendChild(frame);
      frame.onload = function () {
        var win = frame.contentWindow;
        var cleanup = function () {
          URL.revokeObjectURL(href);
          frame.remove();
        };
        win.onafterprint = cleanup;
        win.focus();
        win.print();
        window.setTimeout(cleanup, 120000);
      };
    }

    var downloadButton = document.querySelector("[data-qr-download]");
    var printButton = document.querySelector("[data-qr-print]");
    if (downloadButton) {
      downloadButton.addEventListener("click", function () {
        var poster = buildPoster(qrUrl);
        poster.toBlob(function (blob) {
          blob.arrayBuffer().then(function (buffer) {
            saveBlob(buildPdf(new Uint8Array(buffer), poster.width, poster.height), "femfi-venue-qr.pdf");
          });
        }, "image/jpeg", 0.95);
      });
    }
    if (printButton) {
      printButton.addEventListener("click", function () {
        printPoster(buildPoster(qrUrl));
      });
    }
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

  document.querySelectorAll("[data-choice-search]").forEach(function (input) {
    var list = document.querySelector("[data-choices]");
    var empty = document.querySelector("[data-choice-empty]");
    if (!list) {
      return;
    }
    input.addEventListener("input", function () {
      var query = input.value.trim().toLowerCase();
      var shown = 0;
      list.querySelectorAll(".choice").forEach(function (choice) {
        var match = query === "" || choice.textContent.toLowerCase().indexOf(query) !== -1;
        choice.hidden = !match;
        if (match) {
          shown += 1;
        }
      });
      if (empty) {
        empty.hidden = shown !== 0;
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

  var voteModal = document.querySelector("[data-vote-modal]");
  var voteModalList = voteModal ? voteModal.querySelector("[data-modal-list]") : null;
  var voteModalName = voteModal ? voteModal.querySelector("[data-modal-name]") : null;
  var voteModalMeta = voteModal ? voteModal.querySelector("[data-modal-meta]") : null;

  function fillVoteModal(source) {
    if (!voteModal || !source) {
      return;
    }
    var name = source.getAttribute("data-name") || "";
    var department = source.getAttribute("data-department") || "";
    var count = source.querySelectorAll("li").length;
    voteModalName.textContent = name;
    voteModalMeta.textContent = (department ? department + " · " : "") + count + (count === 1 ? " vote" : " votes");
    voteModalList.innerHTML = source.querySelector("ul").innerHTML;
    voteModal.setAttribute("data-candidate", source.getAttribute("data-candidate") || "");
  }

  function refreshVoteModal() {
    if (!voteModal || !voteModal.open) {
      return;
    }
    var id = voteModal.getAttribute("data-candidate");
    var source = id ? document.querySelector('[data-voter-source][data-candidate="' + id + '"]') : null;
    if (!source) {
      voteModal.close();
      return;
    }
    fillVoteModal(source);
  }

  if (voteModal) {
    document.addEventListener("click", function (event) {
      var opener = event.target.closest ? event.target.closest("[data-view-votes]") : null;
      if (opener) {
        event.preventDefault();
        var row = opener.closest(".rank-row");
        var source = row ? row.querySelector("[data-voter-source]") : null;
        if (!source) {
          return;
        }
        fillVoteModal(source);
        if (!voteModal.open) {
          voteModal.showModal();
        }
        return;
      }
      if (event.target.closest && event.target.closest("[data-modal-close]")) {
        voteModal.close();
      }
    });
    voteModal.addEventListener("click", function (event) {
      if (event.target === voteModal) {
        voteModal.close();
      }
    });
    voteModal.addEventListener("close", function () {
      voteModal.removeAttribute("data-candidate");
    });
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
          refreshVoteModal();
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
