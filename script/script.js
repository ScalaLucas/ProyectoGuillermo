/* ============================================================
   Datos de Marcelo Ragonese Propiedades
   ============================================================ */

window.GP = {
  contacto: {
    direccion: "Bolívar 699, Ramos Mejía",
    telefonos: "+54 9 11 4673 8707",
    email: "marceloragonesepropiedades@gmail.com",
    wa: "5491146738707",
    instagram: "https://www.instagram.com/marceloragonese.prop/",
    matricula: "Colegio Martillero de La Matanza · Matrícula 070 - 1160",
    horarios: "Lun a Vie 9–18h · Sáb 9–13h"
  },

  properties: [] // se carga desde api/propiedades.php (ver cargarPropiedades)
};

/* ============================================================
   Marcelo Ragonese Propiedades — main.js
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
  function normalizeTxt(s) { return String(s || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase(); }
  function videoEmbedUrl(url) {
    var yt = String(url).match(/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/);
    if (yt) return "https://www.youtube.com/embed/" + yt[1];
    var vimeo = String(url).match(/vimeo\.com\/(?:video\/)?(\d+)/);
    if (vimeo) return "https://player.vimeo.com/video/" + vimeo[1];
    if (/matterport\.com|kuula\.co|my\.matterport\.com/i.test(url)) return url;
    return null;
  }

  /* ---------- Favoritos (localStorage) ---------- */
  function getFavs() { try { return JSON.parse(localStorage.getItem("gp_favs") || "{}"); } catch (e) { return {}; } }
  function toggleFav(id) {
    var f = getFavs();
    if (f[id]) { delete f[id]; } else { f[id] = true; }
    try { localStorage.setItem("gp_favs", JSON.stringify(f)); } catch (e) {}
    refreshFavCount();
    return !!f[id];
  }
  // Se exponen para que favoritos.html (que no comparte este cierre) pueda leerlos/usarlos.
  GP.getFavs = getFavs;
  GP.toggleFav = toggleFav;
  function refreshFavCount() {
    var n = Object.keys(getFavs()).length;
    $all("[data-fav-count]").forEach(function (el) {
      el.textContent = n ? " (" + n + ")" : "";
    });
  }

  /* ---------- Tarjeta de propiedad ---------- */
  var ICON_AREA = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18-3v3a2 2 0 0 1-2 2h-3M3 16v3a2 2 0 0 0 2 2h3m8-5v3a2 2 0 0 1-2 2h-3"/></svg>';
  var ICON_BED = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 18v-6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v6M2 18v2M22 18v2M6 10V6a2 2 0 0 1 2-2h4M2 14h20"/></svg>';

  /* ---------- Coordenadas aproximadas por zona (para el mapa de la ficha) ----------
     Solo se usan como respaldo cuando la propiedad no tiene lat/lng exactas
     (la mayoría sí las tiene, cargadas desde el panel). Incluye las zonas
     reales que hoy aparecen en los datos. */
  var ZONA_COORDS = {
    "Ramos Mejía": [-34.6520, -58.5619],
    "Ramos Mejía Sur": [-34.6605, -58.5665],
    "Ramos Mejía Norte": [-34.6420, -58.5605],
    "Villa Luzuriaga": [-34.6656, -58.5897],
    "Lomas del Mirador": [-34.6690, -58.5340],
    "La Fraternidad": [-34.6740, -58.5480],
    "Ciudadela": [-34.6380, -58.5410],
    "Villa del Dique": [-34.6570, -58.5720],
    "Gregorio de Laferrere": [-34.7430, -58.5980],
    "San Justo (Centro)": [-34.6780, -58.5610],
    "San Justo": [-34.6780, -58.5610],
    "Villa Sarmiento": [-34.6351, -58.5736],
    "Caseros": [-34.6047, -58.5613],
    "San Martín": [-34.5764, -58.5385],
    "Francisco Álvarez": [-34.6167, -58.8333],
    "Ituzaingó": [-34.6500, -58.6700],
    "Haedo": [-34.6403, -58.5928]
  };

  function cardHTML(p) {
    var fav = getFavs()[p.id];
    var meta = '<span>' + p.m2.toLocaleString("es-AR") + ' m²</span>';
    if (p.dorm > 0) meta += '<span>· ' + p.dorm + (p.dorm === 1 ? ' dorm' : ' dorm') + '</span>';
    if (p.banos > 0) meta += '<span>· ' + p.banos + (p.banos === 1 ? ' baño' : ' baños') + '</span>';
    var stats = '<span>' + ICON_AREA + p.m2.toLocaleString("es-AR") + ' m²</span>';
    if (p.dorm > 0) stats += '<span>' + ICON_BED + p.dorm + '</span>';
    var foto = (p.fotos && p.fotos.length)
      ? '<img src="' + esc(p.fotos[0]) + '" alt="' + esc(p.direccion) + '" loading="lazy">'
      : '<span class="card__ph">FOTO · ' + esc(p.tipo) + '</span>';
    return '' +
      '<article class="card reveal" data-id="' + p.id + '">' +
        '<div class="card__media">' +
          foto +
          '<div class="card__overlay"><span>Ver detalles</span></div>' +
          '<div class="card__stats">' + stats + '</div>' +
          '<div class="card__tags">' +
            '<span class="card__tag">' + esc(p.operacion) + '</span>' +
            '<span class="card__tag card__tag--type">' + esc(p.tipo) + '</span>' +
          '</div>' +
          '<button class="card__fav' + (fav ? ' active' : '') + '" data-fav="' + p.id + '" aria-label="Guardar">' + (fav ? '♥' : '♡') + '</button>' +
          '<a class="card__fav card__share" href="reporte.html?id=' + encodeURIComponent(p.id) + '" target="_blank" aria-label="Compartir / Imprimir reporte" title="Compartir / Imprimir reporte">📤</a>' +
        '</div>' +
        '<div class="card__body">' +
          '<div class="card__zone">' + esc(p.zona) + '</div>' +
          '<h3 class="card__title">' + esc(p.titulo || p.direccion) + '</h3>' +
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
    $all(".card__share", container).forEach(function (btn) {
      btn.addEventListener("click", function (e) { e.stopPropagation(); });
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
      var setMenu = function (open) {
        menu.classList.toggle("open", open);
        burger.classList.toggle("is-open", open);
        burger.setAttribute("aria-expanded", open ? "true" : "false");
        document.body.style.overflow = open ? "hidden" : "";
      };
      burger.addEventListener("click", function () { setMenu(!menu.classList.contains("open")); });
      $all("a", menu).forEach(function (a) { a.addEventListener("click", function () { setMenu(false); }); });
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

  /* Rellena el <select> de zona del buscador del hero con las zonas que
     realmente existen en las propiedades cargadas (ordenadas por cantidad). */
  function poblarZonasHero() {
    var sel = $("#hs-zona");
    if (!sel) return;
    var conteo = {};
    GP.properties.forEach(function (p) { if (p.zona) conteo[p.zona] = (conteo[p.zona] || 0) + 1; });
    var zonas = Object.keys(conteo).sort(function (a, b) {
      return conteo[b] - conteo[a] || a.localeCompare(b, "es");
    });
    if (!zonas.length) return;
    var previa = sel.value;
    sel.innerHTML = '<option value="">Todas</option>' + zonas.map(function (z) {
      return '<option value="' + esc(z) + '">' + esc(z) + "</option>";
    }).join("");
    if (previa) sel.value = previa;
  }

  /* ---------- Home: destacadas ---------- */
  function initFeatured() {
    var wrap = $("#featured");
    if (!wrap) return;
    var list = GP.properties.filter(function (p) { return p.destacado && p.fotos && p.fotos.length; }).slice(0, 6);
    wrap.innerHTML = list.map(cardHTML).join("");
    wireCards(wrap);
  }

  /* ---------- Listado ---------- */
  var SUP_BUCKETS = [
    { id: "0-50", label: "Hasta 50 m²", min: 0, max: 50 },
    { id: "50-100", label: "50 a 100 m²", min: 50, max: 100 },
    { id: "100-150", label: "100 a 150 m²", min: 100, max: 150 },
    { id: "150-250", label: "150 a 250 m²", min: 150, max: 250 },
    { id: "250-500", label: "250 a 500 m²", min: 250, max: 500 },
    { id: "500+", label: "Más de 500 m²", min: 500, max: Infinity }
  ];
  var CARAC_CATALOGO = null; // se carga desde api/caracteristicas.php — mismo catálogo que usa el panel
  function cargarCatalogoCaracteristicas() {
    return fetch("api/caracteristicas.php")
      .then(function (r) { return r.ok ? r.json() : {}; })
      .then(function (data) { CARAC_CATALOGO = (data && typeof data === "object") ? data : {}; })
      .catch(function () { CARAC_CATALOGO = {}; });
  }

  function precioNumero(p) { return parseInt(String(p.precio || "").replace(/[^\d]/g, ""), 10) || 0; }
  function caracTiene(p, etiqueta) {
    var grupos = p.caracteristicas || {};
    for (var g in grupos) {
      if (grupos[g] && Object.prototype.hasOwnProperty.call(grupos[g], etiqueta)) {
        var v = String(grupos[g][etiqueta]).trim().toLowerCase();
        return v === "sí" || v === "si" || (etiqueta === "Cocheras" && parseInt(v, 10) > 0);
      }
    }
    return false;
  }
  function supBucketOf(m2) {
    for (var i = 0; i < SUP_BUCKETS.length; i++) { if (m2 >= SUP_BUCKETS[i].min && m2 < SUP_BUCKETS[i].max) return SUP_BUCKETS[i].id; }
    return SUP_BUCKETS[SUP_BUCKETS.length - 1].id;
  }

  var listState = { op: "", tipo: [], zona: [], amb: [], sup: [], carac: [], precioDesde: null, precioHasta: null, sort: "destacadas", q: "" };

  function baseListadoOp() {
    // universo de la operación actual (para armar los contadores de cada filtro)
    return GP.properties.filter(function (p) { return p.fotos && p.fotos.length && (!listState.op || p.operacion === listState.op); });
  }
  function applyListState(lista) {
    return lista.filter(function (p) {
      if (listState.q) {
        var q = normalizeTxt(listState.q);
        var enId = normalizeTxt(p.id).indexOf(q) !== -1;
        var enDireccion = normalizeTxt(p.direccion).indexOf(q) !== -1;
        var enZona = normalizeTxt(p.zona).indexOf(q) !== -1;
        if (!enId && !enDireccion && !enZona) return false;
      }
      if (listState.tipo.length && listState.tipo.indexOf(p.tipo) === -1) return false;
      if (listState.zona.length && listState.zona.indexOf(p.zona) === -1) return false;
      if (listState.amb.length) {
        var ambKey = p.amb >= 5 ? "5+" : String(p.amb);
        if (listState.amb.indexOf(ambKey) === -1) return false;
      }
      if (listState.sup.length && listState.sup.indexOf(supBucketOf(p.m2)) === -1) return false;
      if (listState.carac.length && !listState.carac.every(function (c) { return caracTiene(p, c); })) return false;
      var precio = precioNumero(p);
      if (listState.precioDesde !== null && precio < listState.precioDesde) return false;
      if (listState.precioHasta !== null && precio > listState.precioHasta) return false;
      return true;
    });
  }
  function sortList(lista) {
    var arr = lista.slice();
    switch (listState.sort) {
      case "precio-asc": arr.sort(function (a, b) { return precioNumero(a) - precioNumero(b); }); break;
      case "precio-desc": arr.sort(function (a, b) { return precioNumero(b) - precioNumero(a); }); break;
      case "amb-asc": arr.sort(function (a, b) { return a.amb - b.amb; }); break;
      case "amb-desc": arr.sort(function (a, b) { return b.amb - a.amb; }); break;
      case "zona": arr.sort(function (a, b) { return a.zona.localeCompare(b.zona, "es"); }); break;
      case "actualizado": arr.sort(function (a, b) { return new Date(b.actualizado || 0) - new Date(a.actualizado || 0); }); break;
      default: arr.sort(function (a, b) { return (b.destacado ? 1 : 0) - (a.destacado ? 1 : 0); });
    }
    return arr;
  }

  function contarPor(lista, campo) {
    var conteo = {};
    lista.forEach(function (p) { conteo[p[campo]] = (conteo[p[campo]] || 0) + 1; });
    return conteo;
  }
  function renderFacetList(containerId, opciones, seleccionadas, onToggle) {
    var el = document.getElementById(containerId);
    if (!el) return;
    el.innerHTML = opciones.map(function (o) {
      var checked = seleccionadas.indexOf(o.value) !== -1;
      return '<label class="lf-item' + (o.count === 0 && !checked ? ' lf-item--empty' : '') + '">' +
        '<input type="checkbox" value="' + esc(o.value) + '"' + (checked ? ' checked' : '') + '>' +
        '<span>' + esc(o.label) + '</span><em>(' + o.count + ')</em>' +
        '</label>';
    }).join("");
    $all("input[type=checkbox]", el).forEach(function (cb) {
      cb.addEventListener("change", function () { onToggle(cb.value, cb.checked); renderList(); });
    });
  }
  function renderFiltrosSidebar() {
    var universo = baseListadoOp();

    var porTipo = contarPor(universo, "tipo");
    renderFacetList("lf-tipo", ["Departamento", "Casa", "Chalet", "PH", "Dúplex", "Tríplex", "Terreno", "Lote", "Local", "Salón", "Oficina", "Depósito", "Cochera"].map(function (t) {
      return { value: t, label: t, count: porTipo[t] || 0 };
    }), listState.tipo, function (v, on) { toggleEnLista(listState.tipo, v, on); });

    // Las zonas salen de las propiedades reales (más las que el visitante ya
    // tenga tildadas, aunque el resto de filtros las dejen en 0), ordenadas por
    // cantidad. Así el filtro nunca queda desfasado de los datos del panel.
    var porZona = contarPor(universo, "zona");
    var zonasOpts = Object.keys(porZona).concat(listState.zona).filter(function (z, i, arr) {
      return z && arr.indexOf(z) === i;
    }).sort(function (a, b) {
      return (porZona[b] || 0) - (porZona[a] || 0) || a.localeCompare(b, "es");
    });
    renderFacetList("lf-zona", zonasOpts.map(function (z) {
      return { value: z, label: z, count: porZona[z] || 0 };
    }), listState.zona, function (v, on) { toggleEnLista(listState.zona, v, on); });

    var ambConteo = {};
    universo.forEach(function (p) { var k = p.amb >= 5 ? "5+" : String(p.amb); ambConteo[k] = (ambConteo[k] || 0) + 1; });
    renderFacetList("lf-amb", ["1", "2", "3", "4", "5+"].map(function (a) {
      return { value: a, label: a === "5+" ? "5 o más ambientes" : (a + (a === "1" ? " ambiente" : " ambientes")), count: ambConteo[a] || 0 };
    }), listState.amb, function (v, on) { toggleEnLista(listState.amb, v, on); });

    var supConteo = {};
    universo.forEach(function (p) { var k = supBucketOf(p.m2); supConteo[k] = (supConteo[k] || 0) + 1; });
    renderFacetList("lf-sup", SUP_BUCKETS.map(function (s) {
      return { value: s.id, label: s.label, count: supConteo[s.id] || 0 };
    }), listState.sup, function (v, on) { toggleEnLista(listState.sup, v, on); });

    renderCaracteristicasFacet(universo);
  }
  function renderCaracteristicasFacet(universo) {
    var el = document.getElementById("lf-carac");
    if (!el || !CARAC_CATALOGO) return;
    var html = "";
    Object.keys(CARAC_CATALOGO).forEach(function (grupo) {
      html += '<div class="lf-subgroup">' + esc(grupo) + '</div>';
      CARAC_CATALOGO[grupo].forEach(function (etiqueta) {
        var count = universo.filter(function (p) { return caracTiene(p, etiqueta); }).length;
        var checked = listState.carac.indexOf(etiqueta) !== -1;
        html += '<label class="lf-item' + (count === 0 && !checked ? ' lf-item--empty' : '') + '">' +
          '<input type="checkbox" value="' + esc(etiqueta) + '"' + (checked ? ' checked' : '') + '>' +
          '<span>' + esc(etiqueta) + '</span><em>(' + count + ')</em></label>';
      });
    });
    el.innerHTML = html;
    $all("input[type=checkbox]", el).forEach(function (cb) {
      cb.addEventListener("change", function () { toggleEnLista(listState.carac, cb.value, cb.checked); renderList(); });
    });
  }
  function toggleEnLista(arr, valor, on) {
    var i = arr.indexOf(valor);
    if (on && i === -1) arr.push(valor);
    if (!on && i !== -1) arr.splice(i, 1);
  }
  function renderChips() {
    var wrap = $("#lf-chips");
    if (!wrap) return;
    var chips = [];
    ["tipo", "zona", "amb", "sup", "carac"].forEach(function (campo) {
      listState[campo].forEach(function (v) { chips.push({ campo: campo, valor: v, label: v }); });
    });
    if (listState.precioDesde !== null) chips.push({ campo: "precioDesde", valor: "", label: "Desde " + listState.precioDesde.toLocaleString("es-AR") });
    if (listState.precioHasta !== null) chips.push({ campo: "precioHasta", valor: "", label: "Hasta " + listState.precioHasta.toLocaleString("es-AR") });
    if (!chips.length) { wrap.innerHTML = '<span class="lf-chips__empty">Sin filtros aplicados</span>'; return; }
    wrap.innerHTML = chips.map(function (c, i) {
      return '<button type="button" class="lf-chip" data-i="' + i + '">' + esc(c.label) + ' ✕</button>';
    }).join("");
    $all(".lf-chip", wrap).forEach(function (btn, i) {
      btn.addEventListener("click", function () {
        var c = chips[i];
        if (c.campo === "precioDesde") { listState.precioDesde = null; var el1 = $("#f-precio-desde"); if (el1) el1.value = ""; }
        else if (c.campo === "precioHasta") { listState.precioHasta = null; var el2 = $("#f-precio-hasta"); if (el2) el2.value = ""; }
        else toggleEnLista(listState[c.campo], c.valor, false);
        renderList();
      });
    });
  }
  function actualizarTituloCrumb() {
    var title = $("#list-title");
    if (title) title.textContent = listState.op === "Alquiler" ? "Propiedades en alquiler" : (listState.op === "Venta" ? "Propiedades en venta" : "Todas las propiedades");
    var crumb = $("#crumb-op"); if (crumb) crumb.textContent = listState.op || "Propiedades";
  }
  function contarFiltrosActivos() {
    var n = listState.tipo.length + listState.zona.length + listState.amb.length + listState.sup.length + listState.carac.length;
    if (listState.precioDesde !== null) n++;
    if (listState.precioHasta !== null) n++;
    return n;
  }
  function renderList() {
    var grid = $("#list-grid"), empty = $("#list-empty"), count = $("#list-count");
    if (!grid) return;
    var base = baseListadoOp();
    var list = sortList(applyListState(base));
    actualizarTituloCrumb();
    if (count) count.textContent = list.length + (list.length === 1 ? " propiedad" : " propiedades");
    renderFiltrosSidebar();
    renderChips();
    var mfc = $("#mobile-filter-count");
    if (mfc) { var n = contarFiltrosActivos(); mfc.textContent = n ? " (" + n + ")" : ""; }
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
  function limpiarFiltros() {
    listState.tipo = []; listState.zona = []; listState.amb = []; listState.sup = []; listState.carac = [];
    listState.precioDesde = null; listState.precioHasta = null; listState.q = "";
    var d = $("#f-precio-desde"); if (d) d.value = "";
    var h = $("#f-precio-hasta"); if (h) h.value = "";
    var q = $("#f-query"); if (q) q.value = "";
  }
  function initListado() {
    if (!$("#list-grid")) return;
    listState.op = param("op") || "";
    if (param("zona")) listState.zona = [param("zona")];
    if (param("tipo")) listState.tipo = [param("tipo")];
    if (param("q")) listState.q = param("q");
    actualizarTituloCrumb();

    var querySel = $("#f-query");
    if (querySel) {
      querySel.value = listState.q;
      querySel.addEventListener("input", function () { listState.q = querySel.value; renderList(); });
    }

    var sortSel = $("#f-sort");
    if (sortSel) sortSel.addEventListener("change", function () { listState.sort = sortSel.value; renderList(); });

    var pd = $("#f-precio-desde");
    if (pd) pd.addEventListener("change", function () { listState.precioDesde = pd.value ? parseInt(pd.value, 10) : null; renderList(); });
    var ph = $("#f-precio-hasta");
    if (ph) ph.addEventListener("change", function () { listState.precioHasta = ph.value ? parseInt(ph.value, 10) : null; renderList(); });

    var clr = $("#filter-clear"); if (clr) clr.addEventListener("click", function () { limpiarFiltros(); renderList(); });
    var clr2 = $("#empty-clear"); if (clr2) clr2.addEventListener("click", function () { limpiarFiltros(); renderList(); });

    // Panel de filtros en mobile: se abre/cierra con el botón "Filtrar".
    var panel = $("#list-filters"), backdrop = $("#lf-backdrop");
    function abrirFiltros() {
      if (panel) panel.classList.add("open");
      if (backdrop) backdrop.classList.add("open");
      document.documentElement.style.overflow = "hidden";
    }
    function cerrarFiltros() {
      if (panel) panel.classList.remove("open");
      if (backdrop) backdrop.classList.remove("open");
      document.documentElement.style.overflow = "";
    }
    var mfb = $("#mobile-filter-btn"); if (mfb) mfb.addEventListener("click", abrirFiltros);
    var lfc = $("#lf-close"); if (lfc) lfc.addEventListener("click", cerrarFiltros);
    if (backdrop) backdrop.addEventListener("click", cerrarFiltros);

    cargarCatalogoCaracteristicas().then(renderList);
  }

  /* ---------- Detalle ---------- */
  function initDetalle() {
    var root = $("#detail");
    if (!root) return;
    var id = param("id");
    var p = GP.properties.filter(function (x) { return x.id === id; })[0] || GP.properties[0];
    var tituloMostrado = p.titulo || p.direccion;
    document.title = tituloMostrado + " · Marcelo Ragonese Propiedades";

    var feats = ["Apto crédito", "Luminoso", "Cerca de transporte", "Excelente ubicación", "Listo para habitar", "Zona comercial"];
    var desc = "Excelente " + p.tipo.toLowerCase() + " en " + p.zona + ", con " + p.m2.toLocaleString("es-AR") + " m²" +
      (p.dorm > 0 ? " y " + p.dorm + (p.dorm === 1 ? " dormitorio" : " dormitorios") : "") +
      ". Una oportunidad para vivir o invertir en una de las zonas más buscadas del Oeste.";

    var specs = '<div class="spec"><div class="spec__v">' + p.m2.toLocaleString("es-AR") + '</div><div class="spec__k">m² totales</div></div>';
    if (p.dorm > 0) specs += '<div class="spec"><div class="spec__v">' + p.dorm + '</div><div class="spec__k">Dormitorios</div></div>';
    if (p.banos > 0) specs += '<div class="spec"><div class="spec__v">' + p.banos + '</div><div class="spec__k">Baños</div></div>';
    specs += '<div class="spec"><div class="spec__v">' + esc(p.tipo) + '</div><div class="spec__k">Tipo</div></div>';

    $("#detail-op").innerHTML = '<span class="tag">' + esc(p.operacion) + '</span><span class="zone">' + esc(p.zona) + '</span>';
    $("#detail-title").textContent = tituloMostrado;
    var addrEl = $("#detail-addr");
    if (addrEl) addrEl.textContent = p.titulo ? p.direccion : "";
    $("#detail-price").textContent = p.precio;
    $("#detail-specs").innerHTML = specs;
    $("#detail-mainph").textContent = "FOTO PRINCIPAL · " + p.tipo;
    if (p.descripcion) {
      $("#detail-desc").innerHTML = p.descripcion.split("\n\n").map(function (par) { return "<p>" + esc(par) + "</p>"; }).join("");
    } else {
      $("#detail-desc").innerHTML =
        '<p>' + esc(desc) + '</p>' +
        '<p>Ubicado en una de las mejores zonas de ' + esc(p.zona) + ', cerca de comercios, transporte, colegios y espacios verdes. Ideal tanto para vivienda como para inversión. Coordiná una visita con nuestro equipo.</p>';
    }
    var techEl = $("#detail-techspecs");
    var GRUPOS_PRIVADOS = ["Información privada de la operación"];
    var gruposPublicos = p.caracteristicas ? Object.keys(p.caracteristicas).filter(function (g) { return GRUPOS_PRIVADOS.indexOf(g) === -1; }) : [];
    if (gruposPublicos.length) {
      $("#detail-feats").innerHTML = "";
      if (techEl) {
        techEl.hidden = false;
        techEl.innerHTML = gruposPublicos.map(function (grupo) {
          var datos = p.caracteristicas[grupo];
          var filas = Object.keys(datos).map(function (k) {
            var etiqueta = k === "Cantidad de pisos" ? "Cantidad de plantas" : k;
            return '<div class="tech-specs__row"><span>' + esc(etiqueta) + '</span><span>' + esc(datos[k]) + '</span></div>';
          }).join("");
          return '<div class="tech-specs__group"><h3>' + esc(grupo) + '</h3><div class="tech-specs__table">' + filas + '</div></div>';
        }).join("");
      }
    } else {
      $("#detail-feats").innerHTML = feats.map(function (f) { return '<div><span>◆</span>' + esc(f) + '</div>'; }).join("");
      if (techEl) techEl.hidden = true;
    }

    if (p.fotos && p.fotos.length) {
      var main = $("#detail-gallery-main");
      var mainIndex = 0;
      function setMain(src, i) {
        mainIndex = i || 0;
        if (main) main.innerHTML = '<img src="' + esc(src) + '" alt="' + esc(p.direccion) + '" loading="eager">';
      }
      setMain(p.fotos[0], 0);
      var s1 = $("#detail-side-1"), s2 = $("#detail-side-2");
      if (s1 && p.fotos[1]) s1.innerHTML = '<img src="' + esc(p.fotos[1]) + '" alt="' + esc(p.direccion) + '" loading="lazy">';
      if (s2 && p.fotos[2]) s2.innerHTML = '<img src="' + esc(p.fotos[2]) + '" alt="' + esc(p.direccion) + '" loading="lazy">' +
        (p.fotos.length > 3 ? '<span class="detail-more">+' + (p.fotos.length - 3) + ' fotos</span>' : '');

      var strip = $("#detail-strip");
      if (strip && p.fotos.length > 1) {
        strip.hidden = false;
        strip.innerHTML = p.fotos.map(function (src, i) {
          return '<button type="button" class="detail-thumb' + (i === 0 ? ' active' : '') + '" data-src="' + esc(src) + '">' +
            '<img src="' + esc(src) + '" alt="Foto ' + (i + 1) + '" loading="lazy"></button>';
        }).join("");
        $all(".detail-thumb", strip).forEach(function (btn, i) {
          btn.addEventListener("click", function () {
            setMain(btn.getAttribute("data-src"), i);
            $all(".detail-thumb", strip).forEach(function (b) { b.classList.remove("active"); });
            btn.classList.add("active");
            window.scrollTo({ top: main.getBoundingClientRect().top + scrollY - 90, behavior: "smooth" });
          });
        });
      }

      /* ---------- Visor (lightbox) ---------- */
      var lightbox = $("#lightbox");
      if (lightbox) {
        var lbImg = $("#lightbox-img"), lbCount = $("#lightbox-count");
        var lbIndex = 0;
        function lbShow() {
          lbImg.src = p.fotos[lbIndex];
          lbImg.alt = p.direccion + " · foto " + (lbIndex + 1);
          if (lbCount) lbCount.textContent = (lbIndex + 1) + " / " + p.fotos.length;
        }
        function lbOpen(i) {
          lbIndex = i || 0;
          lbShow();
          lightbox.hidden = false;
          document.documentElement.style.overflow = "hidden";
        }
        function lbClose() {
          lightbox.hidden = true;
          document.documentElement.style.overflow = "";
        }
        function lbNext() { lbIndex = (lbIndex + 1) % p.fotos.length; lbShow(); }
        function lbPrev() { lbIndex = (lbIndex - 1 + p.fotos.length) % p.fotos.length; lbShow(); }

        if (main) main.addEventListener("click", function () { lbOpen(mainIndex); });
        if (s1 && p.fotos[1]) s1.addEventListener("click", function () { lbOpen(1); });
        if (s2 && p.fotos[2]) s2.addEventListener("click", function () { lbOpen(2); });
        $all(".detail-thumb", strip || document).forEach(function (btn, i) {
          btn.addEventListener("dblclick", function () { lbOpen(i); });
        });

        var closeBtn = $("#lightbox-close"), nextBtn = $("#lightbox-next"), prevBtn = $("#lightbox-prev");
        if (closeBtn) closeBtn.addEventListener("click", lbClose);
        if (nextBtn) nextBtn.addEventListener("click", lbNext);
        if (prevBtn) prevBtn.addEventListener("click", lbPrev);
        lightbox.addEventListener("click", function (e) { if (e.target === lightbox) lbClose(); });
        document.addEventListener("keydown", function (e) {
          if (lightbox.hidden) return;
          if (e.key === "Escape") lbClose();
          else if (e.key === "ArrowRight") lbNext();
          else if (e.key === "ArrowLeft") lbPrev();
        });

        var touchStartX = 0, touchStartY = 0;
        lightbox.addEventListener("touchstart", function (e) {
          touchStartX = e.changedTouches[0].clientX;
          touchStartY = e.changedTouches[0].clientY;
        }, { passive: true });
        lightbox.addEventListener("touchend", function (e) {
          var dx = e.changedTouches[0].clientX - touchStartX;
          var dy = e.changedTouches[0].clientY - touchStartY;
          if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
            if (dx < 0) lbNext(); else lbPrev();
          }
        }, { passive: true });
      }
    }
    $("#aside-ref").textContent = "Ref. " + p.id;
    $("#aside-price").textContent = p.precio;
    $("#aside-wa").href = waLink("Hola, me interesa la propiedad " + p.id + " (" + p.direccion + ").");
    var visitaBtn = $("#aside-visita");
    if (visitaBtn) visitaBtn.href = waLink("Hola, quiero solicitar una visita a la propiedad " + p.id + " (" + p.direccion + ").");
    var reporteBtn = $("#aside-reporte");
    if (reporteBtn) reporteBtn.href = "reporte.html?id=" + encodeURIComponent(p.id);

    var favBtn = $("#aside-fav");
    function syncFav() { var on = !!getFavs()[p.id]; favBtn.innerHTML = (on ? "♥" : "♡") + " " + (on ? "Guardada" : "Guardar"); favBtn.classList.toggle("active", on); }
    syncFav();
    favBtn.addEventListener("click", function () { toggleFav(p.id); syncFav(); });

    /* ---------- Videos ---------- */
    var videosSection = $("#videos-section");
    var videosGrid = $("#detail-videos");
    if (videosSection && videosGrid && Array.isArray(p.videos) && p.videos.length) {
      videosSection.hidden = false;
      videosGrid.innerHTML = p.videos.map(function (url) {
        if (!/^https?:\/\//i.test(url)) {
          return '<div class="video-embed"><video src="' + esc(url) + '" controls preload="metadata"></video></div>';
        }
        var embed = videoEmbedUrl(url);
        if (embed) {
          return '<div class="video-embed"><iframe src="' + esc(embed) + '" title="Video de la propiedad" loading="lazy" ' +
            'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
        }
        return '<a class="video-embed video-embed--link" href="' + esc(url) + '" target="_blank" rel="noopener">Ver video ↗</a>';
      }).join("");
    }

    /* ---------- Mapa de la zona ---------- */
    var mapEl = $("#detail-map");
    if (mapEl && window.L) {
      var esExacta = typeof p.lat === "number" && typeof p.lng === "number";
      var coords = esExacta ? [p.lat, p.lng] : (ZONA_COORDS[p.zona] || ZONA_COORDS["Ramos Mejía"]);
      var mapa = L.map(mapEl, { scrollWheelZoom: false }).setView(coords, esExacta ? 16 : 15);
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 18
      }).addTo(mapa);
      L.marker(coords).addTo(mapa).bindPopup(esc(esExacta ? p.direccion : p.zona));
    }

    /* ---------- Puede interesarte ---------- */
    var simWrap = $("#similar-props");
    if (simWrap) {
      var candidatas = GP.properties.filter(function (x) {
        return x.id !== p.id && x.fotos && x.fotos.length && x.operacion === p.operacion;
      });
      candidatas.sort(function (a, b) {
        function score(x) { return (x.zona === p.zona ? 2 : 0) + (x.tipo === p.tipo ? 1 : 0); }
        return score(b) - score(a);
      });
      var similares = candidatas.slice(0, 4);
      if (similares.length) {
        simWrap.innerHTML = similares.map(cardHTML).join("");
        wireCards(simWrap);
        var seccion = $("#similares-section");
        if (seccion) seccion.hidden = false;
        initReveal();
      }
    }
  }

  /* ---------- Enlaces WhatsApp / contacto dinámicos ---------- */
  function initLinks() {
    $all("[data-wa]").forEach(function (a) { a.href = waLink(a.getAttribute("data-wa") || "Hola Marcelo Ragonese Propiedades, quería hacer una consulta."); });
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
      var txt = "Hola Marcelo Ragonese Propiedades, soy " + nombre + ". Motivo: " + motivo + ". " + msg;
      window.open(waLink(txt), "_blank");
      if (btn) { var t = btn.textContent; btn.textContent = "✓ Abriendo WhatsApp…"; setTimeout(function () { btn.textContent = t; form.reset(); }, 2600); }
    });
  }

  /* ---------- Carga de propiedades (api/propiedades.php) ---------- */
  function cargarPropiedades() {
    return fetch("api/propiedades.php")
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (data) { GP.properties = Array.isArray(data) ? data : []; })
      .catch(function () { GP.properties = []; });
  }

  /* ---------- Init ---------- */
  document.addEventListener("DOMContentLoaded", function () {
    initNav();
    initHeroSearch();
    initLinks();
    initForm();
    initCounters();
    refreshFavCount();

    // Título y breadcrumb del listado: se fijan ya, sin esperar la carga de
    // propiedades por red (evita el flash de "Todas las propiedades").
    if ($("#list-grid")) {
      listState.op = param("op") || "";
      if (param("zona")) listState.zona = [param("zona")];
      if (param("tipo")) listState.tipo = [param("tipo")];
      actualizarTituloCrumb();
    }

    cargarPropiedades().then(function () {
      poblarZonasHero();
      initFeatured();
      initListado();
      initDetalle();
      initReveal();
    });
  });
})();
