/* Grid Pulse - the widget maker on pages/widgets.html. Builds the <iframe>
   code for embed/widget.html from the choices, and shows a live preview. */
(function () {
  "use strict";
  const form = document.querySelector("[data-wg-form]");
  if (!form) return;
  const frame = document.querySelector("[data-wg-frame]");
  const code = document.querySelector("[data-wg-code]");
  const areaRow = document.querySelector("[data-wg-area]");
  const copied = document.querySelector("[data-wg-copied]");
  const site = location.origin + location.pathname.replace(/pages\/widgets\.html$/, "");
  function update() {
    const show = form.show.value;
    areaRow.hidden = show !== "renewable";
    const q = "show=" + show + (show === "renewable" ? "&area=" + form.area.value : "") + "&theme=" + form.theme.value;
    frame.src = "../embed/widget.html?" + q;
    code.value = '<iframe src="' + site + "embed/widget.html?" + q.replace(/&/g, "&amp;") + '" title="Live electricity figures from Grid Pulse" width="300" height="150" style="border:0" loading="lazy"></iframe>';
    copied.textContent = "";
  }
  form.addEventListener("change", update);
  document.querySelector("[data-wg-copy]").addEventListener("click", async () => {
    try { await navigator.clipboard.writeText(code.value); copied.textContent = "Copied."; }
    catch (e) { code.select(); copied.textContent = "Select the code above and copy it."; }
  });
  update();
})();
