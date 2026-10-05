(function () {
  var card = document.querySelector("[data-vote-start]");
  if (card) {
    var voteStart = Number(card.getAttribute("data-vote-start"));
    var serverNow = Number(card.getAttribute("data-server-now"));
    var offset = serverNow - Date.now();
    var clock = card.querySelector("[data-clock]");
    var button = document.getElementById("vote-now");
    var startedDisabled = button ? button.disabled : false;

    function pad(value) {
      return String(value).padStart(2, "0");
    }

    function tick() {
      var remaining = voteStart - (Date.now() + offset);
      if (remaining < 0) {
        remaining = 0;
      }
      var total = Math.floor(remaining / 1000);
      var hours = Math.floor(total / 3600);
      var minutes = Math.floor((total % 3600) / 60);
      var seconds = total % 60;
      if (clock) {
        clock.textContent = pad(hours) + ":" + pad(minutes) + ":" + pad(seconds);
      }
      if (remaining <= 0) {
        if (startedDisabled && button && button.getAttribute("data-reload") === "1") {
          if (!sessionStorage.getItem("voteReloaded")) {
            sessionStorage.setItem("voteReloaded", "1");
            window.location.reload();
            return;
          }
        }
        if (button) {
          button.disabled = false;
        }
      } else {
        sessionStorage.removeItem("voteReloaded");
      }
    }

    function pullClock() {
      window.fetch("status.php", { cache: "no-store" })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            return;
          }
          voteStart = Number(data.voteStart);
          offset = Number(data.serverNow) - Date.now();
          var current = document.body.getAttribute("data-phase");
          if (current && data.phase !== current) {
            window.location.reload();
            return;
          }
          tick();
        })
        .catch(function () {});
    }

    tick();
    window.setInterval(tick, 250);
    window.setInterval(pullClock, 1000);
    document.addEventListener("visibilitychange", function () {
      pullClock();
      tick();
    });
  }

  if (document.body.getAttribute("data-watch") === "1" && !card) {
    window.setInterval(function () {
      window.fetch("status.php", { cache: "no-store" })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          var current = document.body.getAttribute("data-phase");
          if (data && data.ok && current && data.phase !== current) {
            window.location.reload();
          }
        })
        .catch(function () {});
    }, 1000);
  }

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

  document.querySelectorAll("form[data-confirm]").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      if (!window.confirm(form.getAttribute("data-confirm"))) {
        event.preventDefault();
      }
    });
  });

  var codeInput = document.querySelector("[data-code-input]");
  if (codeInput) {
    codeInput.addEventListener("input", function () {
      codeInput.value = codeInput.value.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 6);
    });
  }

  var refreshSeconds = Number(document.body.getAttribute("data-refresh") || "0");
  if (refreshSeconds > 0) {
    window.setTimeout(function () {
      window.location.reload();
    }, refreshSeconds * 1000);
  }

  var ballot = document.querySelector("form[data-ballot]");
  if (ballot) {
    ballot.addEventListener("submit", function () {
      var submit = ballot.querySelector('button[type="submit"]');
      if (submit) {
        window.setTimeout(function () {
          submit.disabled = true;
          submit.textContent = "Submitting";
        }, 0);
      }
    });
  }
})();
