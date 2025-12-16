<?php /* public/success.php */
require __DIR__ . '/../app/helpers.php';
$cfg = af_load_config();
$id = preg_replace('/[^a-zA-Z0-9_:-]/','', $_GET['id'] ?? '');
$path = rtrim($cfg['submissions_dir'],'/').'/'.$id.'/submission.json';
$ok = is_file($path);
$payload = $ok ? json_decode(file_get_contents($path), true) : null;
$step2Files = [];
$step3Files = [];
$importReport = [];

if ($ok) {
  $baseDir = dirname($path);
  $step2Path = $baseDir.'/step2_orari_immagini/files.json';
  $step3Path = $baseDir.'/step3_prodotti/files.json';
  $reportPath = $baseDir.'/step3_prodotti/import_report.json';

  if (is_file($step2Path)) {
    $step2Files = json_decode(file_get_contents($step2Path), true) ?: [];
  }
  if (is_file($step3Path)) {
    $step3Files = json_decode(file_get_contents($step3Path), true) ?: [];
  }
  if (is_file($reportPath)) {
    $importReport = json_decode(file_get_contents($reportPath), true) ?: [];
  }
}

function af_import_status(array $files, array $report, string $kind): array {
  $status = ['present' => false, 'name' => null, 'report' => null];

  if ($kind === 'products') {
    $file = $files['products_csv'] ?? $files['products_export'] ?? null;
    if ($file) {
      $status['present'] = true;
      $status['name'] = $file['name'] ?? basename($file['file'] ?? '');
    }
    if (!empty($report['products'])) $status['report'] = $report['products'];
  }

  if ($kind === 'promos') {
    $file = $files['promos_csv'] ?? null;
    if ($file) {
      $status['present'] = true;
      $status['name'] = $file['name'] ?? basename($file['file'] ?? '');
    }
    if (!empty($report['promos'])) $status['report'] = $report['promos'];
  }

  if ($kind === 'products_images') {
    $file = $files['products_images_zip'] ?? null;
    if ($file) {
      $status['present'] = true;
      $status['name'] = $file['name'] ?? basename($file['file'] ?? '');
    }
  }

  return $status;
}

$imports = [
  'products' => af_import_status($step3Files, $importReport, 'products'),
  'promos'   => af_import_status($step3Files, $importReport, 'promos'),
  'products_images' => af_import_status($step3Files, $importReport, 'products_images')
];
?>
<!DOCTYPE html>
<html lang="it">
  <meta charset="utf-8" />
  <link rel="stylesheet" href="<?= af_base_url('assets/style.css') ?>" />
  <body>
    <div class="container card">
      <?php if ($ok): ?>
      <h2>Richiesta inviata correttamente</h2>
      <p>
        ID invio: <strong><?= htmlspecialchars($id) ?></strong>
      </p>
      <p>
        Ti abbiamo registrato. Il nostro team userà questi dati per configurare
        la tua app.
      </p>

      <div class="recap-card compact">
        <h3>Upload step 2</h3>
        <ul class="list-plain">
          <li>
            Logo: <?= !empty($step2Files['logo']) ? '✅ presente' : '⚠️ mancante' ?>
          </li>
          <li>
            Avatar farmacista: <?= !empty($step2Files['pharmacist_avatar']) ? '✅ presente' : '⚠️ mancante' ?>
          </li>
          <li>
            Foto farmacia: <?= !empty($step2Files['gallery']) ? count($step2Files['gallery']).' file' : 'nessuna foto' ?>
          </li>
        </ul>
      </div>

      <div class="recap-card compact">
        <h3>Import / file caricati</h3>
        <?php foreach ($imports as $kind => $info): ?>
          <div class="import-row <?= $info['present'] ? '' : 'muted' ?>">
            <div class="import-meta">
              <div class="import-title">
                <?= $kind === 'products' ? 'Import prodotti' : ($kind === 'promos' ? 'Import promozioni' : 'Archivio immagini prodotti (.zip)') ?>
              </div>
              <div class="small import-status">
                <?php if ($info['present']): ?>
                  File: <?= htmlspecialchars($info['name'] ?? 'n/d') ?>
                  <?php if (!empty($info['report'])): ?>
                    · esito: OK <?= (int)($info['report']['ok'] ?? 0) ?> / errori <?= (int)($info['report']['fail'] ?? 0) ?>
                  <?php endif; ?>
                <?php else: ?>
                  Non importato o già rimosso
                <?php endif; ?>
              </div>
            </div>
            <?php if ($info['present']): ?>
              <button
                type="button"
                class="btn danger btn-remove-import"
                data-kind="<?= htmlspecialchars($kind) ?>"
              >
                🗑️ Rimuovi
              </button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <p>
        <a class="btn" href="<?= af_base_url('index.php') ?>">Nuovo invio</a>
      </p>
      <?php else: ?>
      <h2>Invio non trovato</h2>
      <p>L'ID non è valido o il contenuto è stato rimosso.</p>
      <?php endif; ?>
    </div>
    <script>
      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, document.title, "index.php");
      }

      const clearAfStorage = () => {
        localStorage.removeItem("af_upload_session");
        localStorage.removeItem("af_form_data_v2");
        localStorage.removeItem("af_upload_manifest");
      };

      const newSendLink = document.querySelector('a[href*="index.php"]');
      if (newSendLink) {
        newSendLink.addEventListener("click", () => clearAfStorage());
      }

      const submissionId = <?= json_encode($id) ?>;
      document.querySelectorAll(".btn-remove-import").forEach((btn) => {
        btn.addEventListener("click", async () => {
          if (!confirm("Confermi la rimozione di questo import?")) return;
          btn.disabled = true;
          btn.textContent = "Rimozione...";

          try {
            const res = await fetch("delete_import.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                submission_id: submissionId,
                kind: btn.dataset.kind,
              }),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || "Errore");

            const row = btn.closest(".import-row");
            if (row) row.classList.add("removed");
            const st = btn.closest(".import-row")?.querySelector(".import-status");
            if (st) st.textContent = json.message || "Import rimosso";
            btn.remove();
          } catch (e) {
            alert(e.message || "Errore durante la rimozione");
            btn.disabled = false;
            btn.textContent = "🗑️ Rimuovi";
          }
        });
      });
    </script>
  </body>
</html>
