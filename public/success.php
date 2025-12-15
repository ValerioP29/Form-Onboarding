<?php /* public/success.php */
require __DIR__ . '/../app/helpers.php';
$cfg = af_load_config();
$id = preg_replace('/[^a-zA-Z0-9_:-]/','', $_GET['id'] ?? '');
$path = rtrim($cfg['submissions_dir'],'/').'/'.$id.'/submission.json';
$ok = is_file($path);
$payload = $ok ? json_decode(file_get_contents($path), true) : null;
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
      <p>
        <a class="btn" href="<?= af_base_url('index.php') ?>">Nuovo invio</a>
      </p>
      <?php else: ?>
      <h2>Invio non trovato</h2>
      <p>L'ID non è valido o il contenuto è stato rimosso.</p>
      <?php endif; ?>
    </div>
    <script>
      localStorage.removeItem("af_upload_session");
      localStorage.removeItem("af_form_data_v2");
      localStorage.removeItem("af_upload_manifest");

      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, document.title, "index.php");
      }
    </script>
  </body>
</html>
