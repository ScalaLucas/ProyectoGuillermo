/* ============================================================
   Datos de Guillermo Propiedades
   ============================================================ */

window.GP = {
  contacto: {
    direccion: "Marcos Sastre 87 · Haedo",
    telefonos: "4712-0868 · 11 3628-1168",
    email: "guillermopropiedades@hotmail.com",
    wa: "5491136281168",
    instagram: "https://www.instagram.com/propiedadesguillermo/",
    matricula: "Colegio de San Martín · Matrícula 1322",
    horarios: "Lun a Vie 9–18h · Sáb 9–13h"
  },

  properties: [
    { id:"LP669476", tipo:"Departamento", operacion:"Venta", zona:"Santos Lugares", direccion:"Anchordosqui y Moriondo 900", precio:"U$S 60.000", m2:38, dorm:1, banos:1, amb:2, destacado:true },
    { id:"LP670446", tipo:"PH", operacion:"Venta", zona:"Santos Lugares", direccion:"Roberto Lage 1500", precio:"U$S 155.000", m2:140, dorm:2, banos:2, amb:4, destacado:true },
    { id:"LP704610", tipo:"Departamento", operacion:"Venta", zona:"Santos Lugares", direccion:"Pío XII y Av. La Plata 1700", precio:"U$S 80.000", m2:71, dorm:2, banos:1, amb:3, destacado:true },
    { id:"LP785566", tipo:"Departamento", operacion:"Venta", zona:"San Martín", direccion:"Perdriel 5200", precio:"U$S 50.000", m2:54, dorm:2, banos:1, amb:3, destacado:true },
    { id:"LP669491", tipo:"Departamento", operacion:"Venta", zona:"Santos Lugares", direccion:"Moriondo 1100", precio:"U$S 56.000", m2:36, dorm:1, banos:1, amb:2, destacado:true },
    { id:"LP785515", tipo:"Departamento", operacion:"Venta", zona:"San Martín", direccion:"Perdriel 5200", precio:"U$S 36.000", m2:36, dorm:1, banos:1, amb:2, destacado:true },
    { id:"LP759064", tipo:"PH", operacion:"Venta", zona:"Caseros", direccion:"Bermúdez 4500", precio:"U$S 35.000", m2:32, dorm:0, banos:0, amb:2, destacado:false },
    { id:"LP769714", tipo:"Terreno", operacion:"Venta", zona:"Francisco Álvarez", direccion:"Ruta 25, Pilar a Moreno 5000", precio:"U$S 145.000", m2:2100, dorm:0, banos:0, amb:0, destacado:false }
  ]
};

/* ============================================================
   Guillermo Propiedades — main.js
   Nav, buscador, filtros, detalle, contadores, reveal, favoritos
   ============================================================ */
(function () {
  "use strict";
  var GP = window.GP || { properties: [], contacto: {} };
  var C = GP.contacto || {};
  var WA = "https://wa.me/" + (C.wa || "");

  /* ---------- Helpers ---------- */
  function $(s, ctx) { return (ctx || document).querySelector(s); }
  function $all(s, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(s)); }
  function param(name) { return new URLSearchParams(location.search).get(name) || ""; }
  function waLink(text) { return WA + "?text=" + encodeURIComponent(text); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c]; }); }

  /* ---------- Favoritos (localStorage) ---------- */
  function getFavs() { try { return JSON.parse(localStorage.getItem("gp_favs") || "{}"); } catch (e) { return {}; } }
  function toggleFav(id) {
    var f = getFavs();
    if (f[id]) { delete f[id]; } else { f[id] = true; }
    try { localStorage.setItem("gp_favs", JSON.stringify(f)); } catch (e) {}
    return !!f[id];
  }

  /* ---------- Tarjeta de propiedad ---------- */
  function cardHTML(p) {
    var fav = getFavs()[p.id];
    var meta = '<span>' + p.m2.toLocaleString("es-AR") + ' m²</span>';
    if (p.dorm > 0) meta += '<span>· ' + p.dorm + (p.dorm === 1 ? ' dorm' : ' dorm') + '</span>';
    if (p.banos > 0) meta += '<span>· ' + p.banos + (p.banos === 1 ? ' baño' : ' baños') + '</span>';
    return '' +
      '<article class="card reveal" data-id="' + p.id + '">' +
        '<div class="card__media">' +
          '<span class="card__ph">FOTO · ' + esc(p.tipo) + '</span>' +
          '<div class="card__tag">' + esc(p.operacion) + '</div>' +
          '<button class="card__fav' + (fav ? ' active' : '') + '" data-fav="' + p.id + '" aria-label="Guardar">' + (fav ? '♥' : '♡') + '</button>' +
        '</div>' +
        '<div class="card__body">' +
          '<div class="card__zone">' + esc(p.zona) + '</div>' +
          '<h3 class="card__title">' + esc(p.direccion) + '</h3>' +
          '<div class="card__price">' + esc(p.precio) + '</div>' +
          '<div class="card__meta">' + meta + '</div>' +
        '</div>' +
      '</article>';
  }

  function wireCards(container) {
    $all(".card", container).forEach(function (card) {
      card.addEventListener("click", function (e) {
        if (e.target.closest("[data-fav]")) return;
        location.href = "propiedad.html?id=" + encodeURIComponent(card.getAttribute("data-id"));
      });
    });
    $all("[data-fav]", container).forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.stopPropagation();
        var on = toggleFav(btn.getAttribute("data-fav"));
        btn.textContent = on ? "♥" : "♡";
        btn.classList.toggle("active", on);
      });
    });
  }

  /* ---------- Navbar ---------- */
  function initNav() {
    var nav = $(".nav");
    if (!nav) return;
    var isHome = nav.getAttribute("data-home") === "true";
    function upd() {
      if (isHome && window.scrollY < 60) nav.classList.add("nav--transparent");
      else nav.classList.remove("nav--transparent");
    }
    upd();
    window.addEventListener("scroll", upd, { passive: true });

    var burger = $(".nav__burger"), menu = $(".mobile-menu");
    if (burger && menu) {
      burger.addEventListener("click", function () { menu.classList.toggle("open"); });
      $all("a", menu).forEach(function (a) { a.addEventListener("click", function () { menu.classList.remove("open"); }); });
    }
  }

  /* ---------- Reveal + contadores ---------- */
  function initReveal() {
    var els = $all(".reveal");
    if (!("IntersectionObserver" in window)) { els.forEach(function (e) { e.classList.add("in"); }); }
    else {
      var io = new IntersectionObserver(function (ents) {
        ents.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add("in"); io.unobserve(en.target); } });
      }, { threshold: 0.12, rootMargin: "0px 0px -6% 0px" });
      els.forEach(function (e) { io.observe(e); });
    }
  }
  function animateCount(el) {
    var target = parseFloat(el.getAttribute("data-count")), suf = el.getAttribute("data-suffix") || "", dur = 1500, t0 = performance.now();
    function step(t) {
      var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * e).toLocaleString("es-AR") + suf;
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  function initCounters() {
    var els = $all("[data-count]");
    if (!els.length) return;
    if (!("IntersectionObserver" in window)) { els.forEach(animateCount); return; }
    var io = new IntersectionObserver(function (ents) {
      ents.forEach(function (en) { if (en.isIntersecting) { animateCount(en.target); io.unobserve(en.target); } });
    }, { threshold: 0.5 });
    els.forEach(function (e) { io.observe(e); });
  }

  /* ---------- Buscador del hero ---------- */
  function initHeroSearch() {
    var form = $("#hero-search");
    if (!form) return;
    var op = "Venta";
    $all(".search__tab", form).forEach(function (t) {
      t.addEventListener("click", function () {
        op = t.getAttribute("data-op");
        $all(".search__tab", form).forEach(function (x) { x.classList.toggle("active", x === t); });
      });
    });
    $(".search__btn", form).addEventListener("click", function () {
      var q = new URLSearchParams();
      q.set("op", op);
      var tipo = $("#hs-tipo").value, zona = $("#hs-zona").value, amb = $("#hs-amb").value;
      if (tipo) q.set("tipo", tipo);
      if (zona) q.set("zona", zona);
      if (amb) q.set("amb", amb);
      location.href = "propiedades.html?" + q.toString();
    });
  }

  /* ---------- Home: destacadas ---------- */
  function initFeatured() {
    var wrap = $("#featured");
    if (!wrap) return;
    var list = GP.properties.filter(function (p) { return p.destacado; }).slice(0, 6);
    wrap.innerHTML = list.map(cardHTML).join("");
    wireCards(wrap);
  }

  /* ---------- Listado ---------- */
  function currentFilters() {
    return {
      operacion: ($("#f-op") && $("#f-op").value) || "",
      tipo: ($("#f-tipo") && $("#f-tipo").value) || "",
      zona: ($("#f-zona") && $("#f-zona").value) || "",
      ambientes: ($("#f-amb") && $("#f-amb").value) || ""
    };
  }
  function applyFilters(f) {
    return GP.properties.filter(function (p) {
      if (f.operacion && p.operacion !== f.operacion) return false;
      if (f.tipo && p.tipo !== f.tipo) return false;
      if (f.zona && p.zona !== f.zona) return false;
      if (f.ambientes && p.amb < parseInt(f.ambientes, 10)) return false;
      return true;
    });
  }
  function renderList() {
    var grid = $("#list-grid"), empty = $("#list-empty"), count = $("#list-count"), title = $("#list-title");
    if (!grid) return;
    var f = currentFilters();
    var list = applyFilters(f);
    if (title) title.textContent = f.operacion === "Alquiler" ? "Propiedades en alquiler" : (f.operacion === "Venta" ? "Propiedades en venta" : "Todas las propiedades");
    if (count) count.textContent = list.length + (list.length === 1 ? " propiedad" : " propiedades");
    if (list.length) {
      grid.innerHTML = list.map(cardHTML).join("");
      grid.style.display = "";
      if (empty) empty.style.display = "none";
      wireCards(grid);
      initReveal();
    } else {
      grid.innerHTML = "";
      grid.style.display = "none";
      if (empty) empty.style.display = "block";
    }
  }
  function initListado() {
    if (!$("#list-grid")) return;
    // precargar filtros desde la URL
    var map = { op: "f-op", tipo: "f-tipo", zona: "f-zona", amb: "f-amb" };
    Object.keys(map).forEach(function (k) {
      var v = param(k), el = document.getElementById(map[k]);
      if (v && el) el.value = v;
    });
    var btn = $("#filter-btn"); if (btn) btn.addEventListener("click", renderList);
    var clr = $("#filter-clear"); if (clr) clr.addEventListener("click", function () {
      ["f-op", "f-tipo", "f-zona", "f-amb"].forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ""; });
      renderList();
    });
    var clr2 = $("#empty-clear"); if (clr2) clr2.addEventListener("click", function () {
      ["f-op", "f-tipo", "f-zona", "f-amb"].forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ""; });
      renderList();
    });
    renderList();
  }

  /* ---------- Detalle ---------- */
  function initDetalle() {
    var root = $("#detail");
    if (!root) return;
    var id = param("id");
    var p = GP.properties.filter(function (x) { return x.id === id; })[0] || GP.properties[0];
    document.title = p.direccion + " · Guillermo Propiedades";

    var feats = ["Apto crédito", "Luminoso", "Cerca de transporte", "Excelente ubicación", "Listo para habitar", "Zona comercial"];
    var desc = "Excelente " + p.tipo.toLowerCase() + " en " + p.zona + ", con " + p.m2.toLocaleString("es-AR") + " m²" +
      (p.dorm > 0 ? " y " + p.dorm + (p.dorm === 1 ? " dormitorio" : " dormitorios") : "") +
      ". Una oportunidad para vivir o invertir en una de las zonas más buscadas del Oeste.";

    var specs = '<div class="spec"><div class="spec__v">' + p.m2.toLocaleString("es-AR") + '</div><div class="spec__k">m² totales</div></div>';
    if (p.dorm > 0) specs += '<div class="spec"><div class="spec__v">' + p.dorm + '</div><div class="spec__k">Dormitorios</div></div>';
    if (p.banos > 0) specs += '<div class="spec"><div class="spec__v">' + p.banos + '</div><div class="spec__k">Baños</div></div>';
    specs += '<div class="spec"><div class="spec__v">' + esc(p.tipo) + '</div><div class="spec__k">Tipo</div></div>';

    $("#detail-op").innerHTML = '<span class="tag">' + esc(p.operacion) + '</span><span class="zone">' + esc(p.zona) + '</span>';
    $("#detail-title").textContent = p.direccion;
    $("#detail-price").textContent = p.precio;
    $("#detail-specs").innerHTML = specs;
    $("#detail-desc").innerHTML =
      '<p>' + esc(desc) + '</p>' +
      '<p>Ubicado en una de las mejores zonas de ' + esc(p.zona) + ', cerca de comercios, transporte, colegios y espacios verdes. Ideal tanto para vivienda como para inversión. Coordiná una visita con nuestro equipo.</p>';
    $("#detail-feats").innerHTML = feats.map(function (f) { return '<div><span>◆</span>' + esc(f) + '</div>'; }).join("");
    $("#detail-mainph").textContent = "FOTO PRINCIPAL · " + p.tipo;
    $("#aside-ref").textContent = "Ref. " + p.id;
    $("#aside-price").textContent = p.precio;
    $("#aside-wa").href = waLink("Hola, me interesa la propiedad " + p.id + " (" + p.direccion + ").");

    var favBtn = $("#aside-fav");
    function syncFav() { var on = !!getFavs()[p.id]; favBtn.innerHTML = (on ? "♥" : "♡") + " " + (on ? "Guardada" : "Guardar"); favBtn.classList.toggle("active", on); }
    syncFav();
    favBtn.addEventListener("click", function () { toggleFav(p.id); syncFav(); });
  }

  /* ---------- Enlaces WhatsApp / contacto dinámicos ---------- */
  function initLinks() {
    $all("[data-wa]").forEach(function (a) { a.href = waLink(a.getAttribute("data-wa") || "Hola Guillermo Propiedades, quería hacer una consulta."); });
    $all("[data-ig]").forEach(function (a) { a.href = C.instagram || "#"; });
  }

  /* ---------- Formulario de contacto ---------- */
  function initForm() {
    var form = $("#contact-form");
    if (!form) return;
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      var motivo = (form.querySelector('[name="motivo"]') || {}).value || "una consulta";
      var nombre = (form.querySelector('[name="nombre"]') || {}).value || "";
      var msg = (form.querySelector('[name="mensaje"]') || {}).value || "";
      // Abre WhatsApp con el mensaje prellenado (sin backend)
      var txt = "Hola Guillermo Propiedades, soy " + nombre + ". Motivo: " + motivo + ". " + msg;
      window.open(waLink(txt), "_blank");
      if (btn) { var t = btn.textContent; btn.textContent = "✓ Abriendo WhatsApp…"; setTimeout(function () { btn.textContent = t; form.reset(); }, 2600); }
    });
  }

  /* ---------- Init ---------- */
  document.addEventListener("DOMContentLoaded", function () {
    initNav();
    initHeroSearch();
    initFeatured();
    initListado();
    initDetalle();
    initLinks();
    initForm();
    initReveal();
    initCounters();
  });
})();
