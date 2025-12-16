(function () {
  const $ = (s, ctx = document) => ctx.querySelector(s);
  const $$ = (s, ctx = document) => Array.from(ctx.querySelectorAll(s));

  const form = $("#afForm");
  const steps = $$(".step");
  const panels = $$(".form-step");

const MANIFEST_KEY = "af_upload_manifest";

let sessionId = localStorage.getItem("af_upload_session");
if (!sessionId) {
  sessionId = crypto.randomUUID
    ? crypto.randomUUID()
    : `${Date.now()}_${Math.random().toString(36).slice(2)}`;
  localStorage.setItem("af_upload_session", sessionId);
}
console.log("SESSION:", sessionId);

const hidSession = document.createElement("input");
hidSession.type = "hidden";
hidSession.name = "session_id";
hidSession.value = sessionId;
form.appendChild(hidSession);

let uploadManifest = [];
try {
  const saved = JSON.parse(localStorage.getItem(MANIFEST_KEY) || "[]");
  if (Array.isArray(saved)) uploadManifest = saved;
} catch {}

let uploadsInProgress = 0;

  const hidManifest = document.createElement("input");
  hidManifest.type = "hidden";
  hidManifest.name = "upload_manifest";
  hidManifest.value = "[]";
  form.appendChild(hidManifest);

  function persistManifest() {
    hidManifest.value = JSON.stringify(uploadManifest);
    localStorage.setItem(MANIFEST_KEY, hidManifest.value);
  }

  persistManifest();

  function activate(target) {
    panels.forEach((p) => p.classList.remove("active"));
    steps.forEach((s) => s.classList.remove("active"));
    const el = $(target);
    if (el) {
      el.classList.add("active");
      const btn = steps.find((b) => b.dataset.target === target);
      if (btn) btn.classList.add("active");
      window.scrollTo({ top: 0, behavior: "smooth" });

      if (target === "#step-7") buildRecap();
    }
  }

  steps.forEach((b) =>
    b.addEventListener("click", () => activate(b.dataset.target))
  );

  $$(".next").forEach((b) =>
    b.addEventListener("click", () => {
      if (!canProceed()) return;
      const cur = panels.findIndex((p) => p.classList.contains("active"));
      const validate = b.dataset.validate;
      if (validate && !runValidation(validate)) return;
      if (!checkProductRequirement(cur)) return;
      if (cur >= 0 && cur < panels.length - 1) {
        const target = "#" + panels[cur + 1].id;
        activate(target);
      }
    })
  );

  $$(".prev").forEach((b) =>
    b.addEventListener("click", () => {
      const cur = panels.findIndex((p) => p.classList.contains("active"));
      if (cur > 0) activate("#" + panels[cur - 1].id);
    })
  );

  function runValidation(step) {
    if (step === "step1") {
      const required = [
        "pharmacy_name",
        "address",
        "vat_cf",
        "referent",
        "email",
      ];
      for (const name of required) {
        const f = form.querySelector(`[name="${name}"]`);
        if (!f || !f.value.trim()) {
          alert("Compila tutti i campi obbligatori.");
          return false;
        }
      }
    }
    return true;
  }

  function canProceed() {
    if (uploadsInProgress > 0) {
      alert("Attendi il termine dei caricamenti prima di procedere.");
      return false;
    }
    return true;
  }

  function checkProductRequirement(currentIndex, force = false) {
    const isStep3 = panels[currentIndex] && panels[currentIndex].id === "step-3";
    if (!isStep3 && !force) return true;
    if (!hasBucket(["products_csv", "products_export"])) {
      console.warn("Nessun file prodotti caricato (opzionale).");
    }

    return true;
  }

  function serializeEntries() {
    return Array.from(new FormData(form).entries());
  }

  function saveLS() {
    localStorage.setItem("af_form_data_v2", JSON.stringify(serializeEntries()));
  }

  form.addEventListener("input", saveLS);
  form.addEventListener("change", (e) => {
    if (e.target.type !== "file") saveLS();
  });

  window.addEventListener("DOMContentLoaded", () => {
    const raw = localStorage.getItem("af_form_data_v2");
    if (!raw) return;
    let entries;
    try {
      entries = JSON.parse(raw) || [];
    } catch {
      entries = [];
    }

    const needServices = maxIndexed(entries, /^services\[(\d+)\]\[name\]$/i);
    const needEvents = maxIndexed(entries, /^events\[(\d+)\]\[title\]$/i);

    ensureServiceRows(needServices);
    ensureEventRows(needEvents);

    for (const [name, value] of entries) {
      const fields = $$("[name]", form).filter(
        (el) => el.name === name && el.type !== "file"
      );
      if (!fields.length) continue;
      fields.forEach((el, idx) => {
        if (idx === 0) el.value = value;
      });
    }
  });

  window.resetCurrentStep = function (stepId) {
    const panel = document.querySelector(stepId);
    if (!panel) return;

    $$("input, textarea, select", panel).forEach((el) => {
      if (el.type === "checkbox" || el.type === "radio") {
        el.checked = false;
      } else if (el.type !== "file") {
        el.value = "";
      } else {
        el.value = null;
      }
    });

    $$(".rep-row:not(:first-child)", panel).forEach((row) => row.remove());

    const bucketsToReset = [];

    if (stepId === "#step-2") {
      bucketsToReset.push("logo", "pharmacist_avatar", "photo_gallery");
    } else if (stepId === "#step-3") {
      bucketsToReset.push(
        "products_csv",
        "products_export",
        "promos_csv",
        "products_images_zip"
      );
    } else if (stepId === "#step-4") {
      bucketsToReset.push("service_img");
    } else if (stepId === "#step-5") {
      bucketsToReset.push("event_img");
    }

    if (bucketsToReset.length) {
      uploadManifest = uploadManifest.filter((x) => {
        if (!bucketsToReset.includes(x.bucket)) return true;
        if (x.bucket === "service_img" && stepId === "#step-4") return false;
        if (x.bucket === "event_img" && stepId === "#step-5") return false;
        return false;
      });
      persistManifest();
      if (stepId === "#step-4") {
        $$("#servicesRepeater .rep-row").forEach((row, idx) =>
          updateUploadedCount("service_img", idx)
        );
      } else if (stepId === "#step-5") {
        $$("#eventsRepeater .rep-row").forEach((row, idx) =>
          updateUploadedCount("event_img", idx)
        );
      } else {
        bucketsToReset.forEach((b) => updateUploadedCount(b));
      }
    }
    saveLS();
  };

  function maxIndexed(entries, regex) {
    let max = 0;
    for (const [name] of entries) {
      const m = name.match(regex);
      if (m) max = Math.max(max, parseInt(m[1], 10) + 1);
    }
    return max;
  }

  
  const CHUNK_SIZE = 2 * 1024 * 1024; // 2MB
  const MAX_CONCURRENCY = 4;

  function findUploadInput(bucket, index = null) {
    if (bucket === "service_img" && index !== null) {
      return document.querySelector(
        `#servicesRepeater .rep-row:nth-child(${index + 1}) .service-upload`
      );
    }

    if (bucket === "event_img" && index !== null) {
      return document.querySelector(
        `#eventsRepeater .rep-row:nth-child(${index + 1}) .event-upload`
      );
    }

    return (
      document.querySelector(`input[name="${bucket}"]`) ||
      document.querySelector(`input[name="${bucket}[]"]`)
    );
  }

  function ensureUploadCountContainer(bucket, input, index = null) {
    const selector =
      `.upload-count-${bucket}` +
      (index !== null ? `[data-index="${index}"]` : "");
    let el = null;

    if (input) {
      const wrapper = input.closest("label") || input.parentElement;
      if (wrapper) {
        el = wrapper.querySelector(selector);
      }
    }

    if (!el) {
      el = document.querySelector(selector);
    }

    if (!el && input) {
      el = document.createElement("div");
      el.className = `upload-count-${bucket}`;
      if (index !== null) el.dataset.index = index;
      // Manteniamo il contatore subito dopo l'input per non rompere il layout.
      input.insertAdjacentElement("afterend", el);
    }

    return el;
  }

  function updateUploadedCount(bucket, index = null) {
    let list;
    try {
      list = JSON.parse(hidManifest.value || "[]");
    } catch {
      list = [];
    }

    const count = list.filter((x) => {
      if (x.bucket !== bucket) return false;
      if (bucket === "service_img") {
        if (index === null)
          return x.service_id === null || x.service_id === undefined;
        return String(x.service_id) === String(index);
      }
      if (bucket === "event_img") {
        if (index === null)
          return x.event_id === null || x.event_id === undefined;
        return String(x.event_id) === String(index);
      }
      return true;
    }).length;

    const input = findUploadInput(bucket, index);
    const el = ensureUploadCountContainer(bucket, input, index);
    if (!el) return;

    el.textContent = count
      ? `${count} file caricati correttamente`
      : "";
  }


async function uploadFileChunked(file, bucket, extra = {}) {
  const totalChunks = Math.ceil(file.size / CHUNK_SIZE) || 1;
  const fileId = crypto.randomUUID
    ? crypto.randomUUID()
    : `${Date.now()}_${Math.random().toString(36).slice(2)}`;
  let next = 0;

  uploadsInProgress++;
  setUploadingState(true);

  const progressWrap = document.createElement("div");
  progressWrap.className = "upload-progress-row";
  progressWrap.innerHTML = `
    <div class="upload-file-name">${file.name}</div>
    <div class="upload-bar-bg">
      <div class="upload-bar" style="width:0%"></div>
    </div>
    <div class="upload-percent">0%</div>
  `;

  const uploadArea = document.querySelector("#uploadProgressArea");
  if (uploadArea) uploadArea.appendChild(progressWrap);
  else document.body.appendChild(progressWrap);

  const bar = progressWrap.querySelector(".upload-bar");
  const pct = progressWrap.querySelector(".upload-percent");

  async function sendChunk(idx) {
    const start = idx * CHUNK_SIZE;
    const end = Math.min(start + CHUNK_SIZE, file.size);
    const blob = file.slice(start, end);

    const fd = new FormData();
    fd.append("session_id", sessionId);
    fd.append("bucket", bucket);
    fd.append("file_id", fileId);
    fd.append("total_chunks", String(totalChunks));
    fd.append("chunk_index", String(idx));
    fd.append("original_name", file.name);
    fd.append("mime", file.type || "application/octet-stream");

    if (extra.service_id !== undefined) fd.append("service_id", extra.service_id);
    if (extra.event_id   !== undefined) fd.append("event_id",   extra.event_id);

    fd.append("chunk", new File([blob], `chunk_${idx}`));

    const res = await fetch("upload.php", { method: "POST", body: fd });
    if (!res.ok) throw new Error("Errore rete");
    return res.json();
  }

  let inFlight = 0;
  let resolveFinal, rejectFinal;
  const donePromise = new Promise((res, rej) => {
    resolveFinal = res;
    rejectFinal = rej;
  });

  function updateProgress() {
    const done = Math.min(next, totalChunks);
    const percent = Math.floor((done / totalChunks) * 100);
    bar.style.width = percent + "%";
    pct.textContent = percent + "%";
  }

  function launchNext() {
    if (next >= totalChunks) {
      if (inFlight === 0) resolveFinal();
      return;
    }

    const idx = next++;
    inFlight++;

    updateProgress();

    sendChunk(idx)
      .then((r) => {
        inFlight--;

        if (r.completed) resolveFinal(r);

        if (next < totalChunks) launchNext();

        if (inFlight === 0 && next >= totalChunks) resolveFinal(r);
      })
      .catch((e) => rejectFinal(e));
  }

  updateProgress();

  for (let i = 0; i < Math.min(MAX_CONCURRENCY, totalChunks); i++) {
    launchNext();
  }

  let final;
  try {
    final = await donePromise;
  } finally {
    uploadsInProgress = Math.max(0, uploadsInProgress - 1);
    setUploadingState(uploadsInProgress > 0);
  }

  bar.style.width = "100%";
  pct.textContent = "100% ✅";
  progressWrap.classList.add("done");

  setTimeout(() => {
    progressWrap.style.transition = "opacity 0.6s";
    progressWrap.style.opacity = 0;
    setTimeout(() => progressWrap.remove(), 600);
  }, 1000);

  if (!final || !final.ok || !final.completed) {
    throw new Error("Upload incompleto");
  }

const replaceBuckets = new Set([
  "products_csv",
  "products_export",
  "promos_csv",
  "products_images_zip",
  "logo",
  "pharmacist_avatar",
]);

 uploadManifest = uploadManifest.filter((x) => {
  if (!replaceBuckets.has(bucket)) return true;
  if (bucket === "service_img" && extra.service_id !== undefined) return true;
  return x.bucket !== bucket;
});

 uploadManifest.push({
  bucket,
  file_id: final.file_id,
  name: final.name,
  path: final.path,
  service_id: extra.service_id ?? null,
  event_id: extra.event_id ?? null,
});

 persistManifest();

 updateUploadedCount(bucket, extra.service_id ?? extra.event_id ?? null);

 return final;

}

  function hookAsyncInput(selector, bucket) {
  const inp = document.querySelector(selector);
  if (!inp) return;

    inp.addEventListener("change", async () => {
      const files = Array.from(inp.files || []);
      if (!files.length) return;

    inp.disabled = true;

      for (const f of files) {
        try {
          await uploadFileChunked(f, bucket);
      } catch (e) {
        alert("Errore durante il caricamento del file: " + (f.name || ""));
      }
    }

    inp.value = "";   
    inp.disabled = false;

    try {
      buildRecap();
    } catch {}
  });
}

  hookAsyncInput('input[name="logo"]', "logo");
  hookAsyncInput('input[name="pharmacist_avatar"]', "pharmacist_avatar");
  hookAsyncInput('input[name="photo_gallery[]"]', "photo_gallery");
  hookAsyncInput('input[name="service_img[]"]', "service_img");
  hookAsyncInput('input[name="event_img[]"]', "event_img");
  hookAsyncInput('input[name="products_csv"]', "products_csv");
  hookAsyncInput('input[name="products_export"]', "products_export");
  hookAsyncInput('input[name="promos_csv"]', "promos_csv");
  hookAsyncInput('input[name="products_images_zip"]', "products_images_zip");

  const servicesRepeater = $("#servicesRepeater");
  const addServiceBtn = $("#addService");

  if (servicesRepeater && addServiceBtn) {
    addServiceBtn.addEventListener("click", () => addServiceRow());
  }

  function addServiceRow() {
  const index = servicesRepeater.querySelectorAll(".rep-row").length;
  const row = document.createElement("div");
  row.className = "rep-row";

  row.innerHTML = `
    <label>
      Nome servizio
      <input name="services[${index}][name]">
    </label>

    <label>
      Prezzo (es. €10 o gratuito)
      <input name="services[${index}][price]">
    </label>

    <div class="check-inline">
      <input type="checkbox" name="services[${index}][booking]" value="1">
      <span>Prenotazione necessaria?</span>
    </div>

    <label>
      Carica immagine del servizio
      <input type="file" accept="image/*" class="service-upload">
      <div class="upload-count-service_img" data-index="${index}"></div>
    </label>

    <label>
      Descrizione breve
      <textarea name="services[${index}][desc]" rows="2"></textarea>
    </label>

    <div class="remove-wrap">
      <button type="button" class="btn danger removeRow">Rimuovi</button>
    </div>
  `;

  servicesRepeater.appendChild(row);
  hookAsyncServiceImg(row);
  row.querySelector(".removeRow").addEventListener("click", () => row.remove());
}


  function hookAsyncServiceImg(ctx) {
  const inp = $(".service-upload", ctx);
  if (!inp) return;

  inp.addEventListener("change", async () => {
  const files = Array.from(inp.files || []);
  if (!files.length) return;

  inp.disabled = true;

  const index = Array.from(
    document.querySelectorAll("#servicesRepeater .rep-row")
  ).indexOf(ctx);

    for (const f of files) {
      try {
        await uploadFileChunked(f, "service_img", { service_id: index });
      updateUploadedCount("service_img", index);
    } catch (e) {
      alert("Errore caricamento immagine servizio");
    }
  }

  inp.value = "";
  inp.disabled = false;

  try {
    buildRecap();
  } catch {}
});

}

  function ensureServiceRows(n) {
    if (!servicesRepeater) return;
    const have = servicesRepeater.querySelectorAll(".rep-row").length;
    for (let i = have; i < n; i++) addServiceRow();
  }

  const eventsRepeater = $("#eventsRepeater");
  const addEventBtn = $("#addEvent");

  if (eventsRepeater && addEventBtn) {
    addEventBtn.addEventListener("click", () => addEventRow());
  }

  function addEventRow() {
    const index = eventsRepeater.querySelectorAll(".rep-row").length;
    const row = document.createElement("div");
    row.className = "rep-row";
    row.innerHTML = `
      <label>
        Titolo evento
        <input name="events[${index}][title]">
      </label>

      <label>
        Data evento
        <input type="date" name="events[${index}][date]">
      </label>

      <label>
        Ora inizio
        <input type="time" name="events[${index}][start]">
      </label>

      <label>
        Ora fine
        <input type="time" name="events[${index}][end]">
      </label>

      <label>
        Posti disponibili (es. 20)
        <input name="events[${index}][seats]">
      </label>

      <label>
        Carica immagine evento
        <input type="file" accept="image/*" class="event-upload">
        <div class="upload-count-event_img" data-index="${index}"></div>
      </label>

      <label>
        Descrizione sintetica
        <textarea name="events[${index}][desc]" rows="2"></textarea>
      </label>

      <div class="remove-wrap">
        <button type="button" class="btn danger removeRow">Rimuovi</button>
      </div>
    `;

    eventsRepeater.appendChild(row);
    hookAsyncEventImg(row);
    row
      .querySelector(".removeRow")
      .addEventListener("click", () => row.remove());
  }

  function hookAsyncEventImg(ctx) {
  const inp = $(".event-upload", ctx);
  if (!inp) return;

  inp.addEventListener("change", async () => {
    const files = Array.from(inp.files || []);
    if (!files.length) return;

    inp.disabled = true;

    const index = Array.from(
      document.querySelectorAll("#eventsRepeater .rep-row")
    ).indexOf(ctx);

    for (const f of files) {
      try {
        await uploadFileChunked(f, "event_img", { event_id: index });
        updateUploadedCount("event_img", index);
      } catch {
        alert("Errore caricamento immagine evento");
      }
    }

    inp.value = "";
    inp.disabled = false;

    try {
      buildRecap();
    } catch {}
  });
}

  function ensureEventRows(n) {
    if (!eventsRepeater) return;
    const have = eventsRepeater.querySelectorAll(".rep-row").length;
    for (let i = have; i < n; i++) addEventRow();
  }

  function isBucketLoaded(bucket) {
    try {
      const list = JSON.parse(hidManifest.value || "[]");
      return list.some((x) => x.bucket === bucket);
    } catch {
      return false;
    }
  }

  function buildRecap() {
    const box = $("#recap_readable");
    if (!box) return;

    const get = (name) =>
      (form.querySelector(`[name="${name}"]`) || {}).value || "";

    let html = "";

    const filledStep1 = get("pharmacy_name") && get("address") && get("email");
    html += `<h3>1) Dati farmacia: ${
      filledStep1 ? "✅ OK" : "⚠ Mancanti"
    }</h3>`;

    const hasLogo = isBucketLoaded("logo");
    html += `<p>Logo: ${hasLogo ? "✅ caricato" : "⚠ non caricato"}</p>`;

    const hasAvatar = isBucketLoaded("pharmacist_avatar");
    html += `<p>Avatar farmacista: ${
      hasAvatar ? "✅ caricato" : "⚠ non caricato"
    }</p>`;

    const photos = JSON.parse(hidManifest.value || "[]").filter(
      (x) => x.bucket === "photo_gallery"
    ).length;
    html += `<p>Foto farmacia: <strong>${photos}</strong></p>`;

    const services = $$("#servicesRepeater .rep-row");
    const servicesCount = services.filter((r) => {
      const v = r.querySelector('input[name*="[name]"]')?.value.trim();
      return v;
    }).length;
    html += `<p>Servizi inseriti: <strong>${servicesCount}</strong></p>`;

    const events = $$("#eventsRepeater .rep-row");
    const eventsCount = events.filter((r) => {
      const v = r.querySelector('input[name*="[title]"]')?.value.trim();
      return v;
    }).length;
    html += `<p>Eventi inseriti: <strong>${eventsCount}</strong></p>`;

    const hasProd =
      isBucketLoaded("products_csv") || isBucketLoaded("products_export");
    html += `<p>Prodotti: ${hasProd ? "✅ caricati" : "⚠ nessun file"}</p>`;

    const web = get("site");
    const fb = get("facebook");
    const ig = get("instagram");
    const socialOk = web || fb || ig;
    html += `<p>Canali digitali: ${
      socialOk ? "✅ forniti" : "⚠ nessun link"
    }</p>`;

    box.innerHTML = html;
  }

  function hasBucket(buckets) {
    try {
      const list = JSON.parse(hidManifest.value || "[]");
      return list.some((x) => buckets.includes(x.bucket));
    } catch {
      return false;
    }
  }

  function setUploadingState(active) {
    const buttons = [
      ...$$(".next"),
      ...$$(".step"),
      ...$$("button[type='submit']"),
    ];
    buttons.forEach((b) => {
      b.disabled = active;
      b.classList.toggle("disabled", active);
    });
  }

  function restoreManifestUI() {
    persistManifest();
    const seen = new Set();
    uploadManifest.forEach((item) => {
      const key = `${item.bucket}::${item.service_id ?? item.event_id ?? ""}`;
      if (seen.has(key)) return;
      seen.add(key);
      const index = item.service_id ?? item.event_id ?? null;
      updateUploadedCount(item.bucket, index);
    });
    try {
      buildRecap();
    } catch {}
  }

  form.addEventListener("submit", (e) => {
    if (!canProceed() || !checkProductRequirement(panels.findIndex((p) => p.id === "step-3"), true)) {
      e.preventDefault();
    }
  });

  restoreManifestUI();
})();
