/* ============================================================
   Asistente IA — chat de soporte (Gemini vía backend propio)
   No expone ninguna clave: solo llama a api/chat.php y api/lead.php
   ============================================================ */
(function () {
  "use strict";

  var $ = function (sel, scope) { return (scope || document).querySelector(sel); };
  var $$ = function (sel, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(sel)); };
  var escHTML = function (s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  };
  function safe(fn, name) { try { fn(); } catch (e) { console.warn("[chat:" + name + "]", e); } }

  var STORAGE_KEY = "mr_chat_historial_v1";
  var PANEL_KEY = "mr_chat_abierto_v1";
  var SALUDO = "¡Hola! Soy el asistente virtual de Marcelo Ragonese Propiedades. Puedo ayudarte con dudas sobre compra, venta, alquiler o tasaciones, y recomendarte propiedades. ¿En qué te ayudo?";

  function cargarHistorial() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      var arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr : [];
    } catch (e) { return []; }
  }
  function guardarHistorial(hist) {
    try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(hist.slice(-24))); } catch (e) {}
  }
  function guardarPanelAbierto(v) {
    try { sessionStorage.setItem(PANEL_KEY, v ? "1" : "0"); } catch (e) {}
  }
  function cargarPanelAbierto() {
    try { return sessionStorage.getItem(PANEL_KEY) === "1"; } catch (e) { return false; }
  }

  function initChat() {
    var root = $("[data-ai-chat]");
    if (!root) return;

    var toggle = $("[data-ai-toggle]", root);
    var panel = $("[data-ai-panel]", root);
    var closeBtn = $("[data-ai-close]", root);
    var messagesEl = $("[data-ai-messages]", root);
    var form = $("[data-ai-form]", root);
    var input = $("[data-ai-input]", root);
    var leadBox = $("[data-ai-lead]", root);
    if (!toggle || !panel || !messagesEl || !form || !input) return;

    root.hidden = false;

    var historial = cargarHistorial();
    var enviando = false;
    var leadPendiente = null; // guarda el último resumen para adjuntar al lead

    function scrollAbajo() {
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function pintarBurbuja(role, texto) {
      var div = document.createElement("div");
      div.className = "ai-msg ai-msg--" + (role === "user" ? "user" : "bot");
      div.innerHTML = "<p>" + escHTML(texto).replace(/\n/g, "<br>") + "</p>";
      messagesEl.appendChild(div);
      scrollAbajo();
      return div;
    }

    function pintarTyping() {
      var div = document.createElement("div");
      div.className = "ai-msg ai-msg--bot ai-msg--typing";
      div.setAttribute("data-ai-typing", "1");
      div.innerHTML = "<span></span><span></span><span></span>";
      messagesEl.appendChild(div);
      scrollAbajo();
      return div;
    }

    function quitarTyping() {
      var t = $("[data-ai-typing]", messagesEl);
      if (t) t.remove();
    }

    function pintarPropiedades(ids) {
      var datos = (window.GP && window.GP.properties) || [];
      var props = ids.map(function (id) {
        return datos.filter(function (p) { return p.id === id; })[0];
      }).filter(Boolean);
      if (!props.length) return;

      var wrap = document.createElement("div");
      wrap.className = "ai-msg ai-msg--bot ai-props";
      wrap.innerHTML = props.map(function (p) {
        return '<a class="ai-prop-card" href="propiedad.html?id=' + encodeURIComponent(p.id) + '">' +
          '<div class="ai-prop-card__body">' +
          '<div class="ai-prop-card__zone">' + escHTML(p.zona) + " · " + escHTML(p.tipo) + '</div>' +
          '<div class="ai-prop-card__addr">' + escHTML(p.direccion) + '</div>' +
          '<div class="ai-prop-card__price">' + escHTML(p.precio) + '</div>' +
          '<div class="ai-prop-card__specs">' + p.amb + ' amb · ' + p.m2 + ' m²</div>' +
          '</div></a>';
      }).join("");
      messagesEl.appendChild(wrap);
      scrollAbajo();
    }

    function mostrarFormularioLead(resumen) {
      if (!leadBox) return;
      leadPendiente = resumen || "";
      leadBox.hidden = false;
      leadBox.innerHTML =
        '<p class="ai-lead__intro">Para que un asesor te contacte, dejame tus datos:</p>' +
        '<form data-ai-lead-form>' +
        '<input type="text" name="nombre" placeholder="Tu nombre" required maxlength="120">' +
        '<input type="tel" name="telefono" placeholder="Tu teléfono" required maxlength="40">' +
        '<input type="email" name="email" placeholder="Tu email (opcional)" maxlength="140">' +
        '<button type="submit" class="btn btn--accent btn--block">Continuar con un asesor</button>' +
        '</form>';
      var f = $("[data-ai-lead-form]", leadBox);
      if (f) f.addEventListener("submit", onLeadSubmit);
      scrollAbajo();
    }

    function onLeadSubmit(e) {
      e.preventDefault();
      var f = e.target;
      var btn = f.querySelector('button[type="submit"]');
      var nombre = (f.elements.nombre.value || "").trim();
      var telefono = (f.elements.telefono.value || "").trim();
      var email = (f.elements.email.value || "").trim();
      if (!nombre || !telefono) return;

      if (btn) { btn.disabled = true; btn.textContent = "Enviando…"; }

      fetch("api/lead.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          nombre: nombre, telefono: telefono, email: email,
          motivo: "Consulta iniciada en el asistente de la web",
          resumen: leadPendiente || ""
        })
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (leadBox) leadBox.hidden = true;
        if (data && data.ok && data.whatsapp_link) {
          var div = document.createElement("div");
          div.className = "ai-msg ai-msg--bot";
          div.innerHTML = "<p>¡Gracias, " + escHTML(nombre) + "! Ya anotamos tu consulta. " +
            "Podés seguir hablando acá o continuar directo por WhatsApp.</p>" +
            '<a class="btn btn--wa" style="margin-top:8px;display:inline-flex" target="_blank" rel="noopener" href="' +
            data.whatsapp_link + '">Continuar por WhatsApp →</a>';
          messagesEl.appendChild(div);
        } else {
          pintarBurbuja("bot", "No pude registrar tus datos justo ahora. Escribinos directo por WhatsApp, por favor.");
        }
        scrollAbajo();
      }).catch(function () {
        if (leadBox) leadBox.hidden = true;
        pintarBurbuja("bot", "No pude registrar tus datos justo ahora. Escribinos directo por WhatsApp, por favor.");
      });
    }

    function enviarMensaje(texto) {
      if (enviando) return;
      enviando = true;
      pintarBurbuja("user", texto);
      var historialParaEnviar = historial.slice();
      historial.push({ role: "user", text: texto });
      guardarHistorial(historial);

      pintarTyping();

      // Los picos de saturación de la IA suelen durar segundos: si la primera
      // respuesta es el mensaje de "mucha demanda", reintentamos solo o dos
      // veces en silencio (el visitante sigue viendo "escribiendo…") antes de
      // mostrarle nada.
      var MAX_REINTENTOS = 2;

      function pedir(intento) {
        fetch("api/chat.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ message: texto, history: historialParaEnviar })
        }).then(function (r) { return r.json(); }).then(function (data) {
          var esFallback = !data || !data.reply || data.fallback === true;
          if (esFallback && !data.rate_limited && intento < MAX_REINTENTOS) {
            setTimeout(function () { pedir(intento + 1); }, 2500);
            return;
          }
          quitarTyping();
          enviando = false;
          if (!data || !data.reply) {
            pintarBurbuja("bot", "No pude responder justo ahora. Escribinos por WhatsApp al +54 9 11 4673 8707 y te contestamos enseguida.");
            return;
          }
          pintarBurbuja("bot", data.reply);
          historial.push({ role: "model", text: data.reply });
          guardarHistorial(historial);

          if (Array.isArray(data.matched_property_ids) && data.matched_property_ids.length) {
            pintarPropiedades(data.matched_property_ids);
          }
          if (data.ready_for_handoff) {
            mostrarFormularioLead(texto);
          }
        }).catch(function () {
          if (intento < MAX_REINTENTOS) {
            setTimeout(function () { pedir(intento + 1); }, 2500);
            return;
          }
          quitarTyping();
          enviando = false;
          pintarBurbuja("bot", "No pude conectarme justo ahora. Escribinos por WhatsApp al +54 9 11 4673 8707 y te contestamos enseguida.");
        });
      }

      pedir(0);
    }

    // Repinta la conversación guardada (o el saludo, si es la primera vez)
    // para que al navegar a otra página no parezca que el chat se reinició.
    if (historial.length) {
      historial.forEach(function (turno) {
        pintarBurbuja(turno.role === "user" ? "user" : "bot", turno.text);
      });
    } else {
      pintarBurbuja("bot", SALUDO);
    }

    toggle.addEventListener("click", function () {
      var abierto = panel.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", abierto ? "true" : "false");
      guardarPanelAbierto(abierto);
      if (abierto) setTimeout(function () { input.focus(); }, 150);
    });
    if (closeBtn) closeBtn.addEventListener("click", function () {
      panel.classList.remove("is-open");
      toggle.setAttribute("aria-expanded", "false");
      guardarPanelAbierto(false);
    });

    // Si el visitante ya lo tenía abierto en la página anterior, lo dejamos abierto.
    if (cargarPanelAbierto()) {
      panel.classList.add("is-open");
      toggle.setAttribute("aria-expanded", "true");
      scrollAbajo();
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var texto = (input.value || "").trim();
      if (!texto) return;
      input.value = "";
      enviarMensaje(texto);
    });
  }

  function boot() { safe(initChat, "initChat"); }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
