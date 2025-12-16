<?php
require __DIR__ . '/../app/helpers.php';
af_ensure_dirs();
$csrf = af_generate_csrf();
$cfg  = af_load_config();
$sessionId = af_random_id(12);
?>

<!DOCTYPE html>
<html lang="it">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Onboarding – Assistente Farmacia</title>
    <link rel="stylesheet" href="<?= af_base_url('assets/style.css') ?>" />
  </head>
  <body>
    <header class="site-header">
      <div class="container">
        <h1>Assistente Farmacia – Configurazione iniziale</h1>
        <p class="muted">
          Compila i campi. Ogni voce indica dove verrà usata nell'app. Puoi
          salvare e riprendere più tardi.
        </p>
      </div>
    </header>

    <main class="container">
      <div class="stepper">
        <button class="step active" data-target="#step-1">
          1. Dati farmacia
        </button>
        <button class="step" data-target="#step-2">2. Logo & Immagini</button>
        <button class="step" data-target="#step-3">3. Prodotti</button>
        <button class="step" data-target="#step-4">4. Servizi</button>
        <button class="step" data-target="#step-5">5. Eventi</button>
        <button class="step" data-target="#step-6">6. Social</button>
        <button class="step" data-target="#step-7">Riepilogo</button>
      </div>

      <form
        id="afForm"
        class="card"
        method="post"
        enctype="multipart/form-data"
        action="<?= af_base_url('submit.php') ?>"
      >
        <input
          type="hidden"
          name="csrf_token"
          value="<?= htmlspecialchars($csrf) ?>"
        />

        <!-- STEP 1 -->
        <section id="step-1" class="form-step active">
          <div class="empty-form">
            <button
              type="button"
              onclick="resetCurrentStep('#step-1')"
              class="btn danger"
            >
              Svuota sezione
            </button>
          </div>
          <h2>1) Dati anagrafici della farmacia</h2>
          <div class="grid">
            <label
              >Nome completo della farmacia
              <input
                name="pharmacy_name"
                required
                maxlength="120"
                placeholder="Farmacia San Luca S.r.l."
              />
              <small>Visibile in intestazione e schermata iniziale</small>
            </label>

            <label
              >Indirizzo completo
              <input
                name="address"
                required
                placeholder="Via Roma 10, 00100 Roma (RM)"
              />
              <small>Per mappe e geolocalizzazione</small>
            </label>

            <label
              >P.IVA o C.F.
              <input
                name="vat_cf"
                required
                maxlength="32"
                placeholder="IT01234567890"
              />
              <small>Amministrazione e legale</small>
            </label>

            <label
              >Referente (nome e cognome)
              <input
                name="referent"
                required
                maxlength="120"
                placeholder="Mario Rossi"
              />
              <small>Per comunicazioni ufficiali</small>
            </label>

            <label
              >Email principale
              <input
                type="email"
                name="email"
                required
                placeholder="info@farmacia.it"
              />
            </label>

            <label
              >Telefono fisso
              <input name="phone" placeholder="0773 123456" />
            </label>

            <label
              >Numero WhatsApp
              <input name="whatsapp" placeholder="+39 333 1234567" />
              <small
                >Usato per pulsante WhatsApp e gestione richieste da
                pannello</small
              >
            </label>
          </div>

          <h3 style="margin-top: 20px">Orari di apertura</h3>
          <small
            >Compila mattina e/o pomeriggio. Se lasci vuoto un giorno →
            chiuso.</small
          >

          <div
            class="opening-hours"
            style="
              margin-top: 12px;
              display: flex;
              flex-direction: column;
              gap: 10px;
            "
          >
            <!-- LUNEDÌ -->
            <div class="weekday-row">
              <span style="width: 90px; display: inline-block">Lunedì</span>

              <!-- MATTINA -->
              <select name="hours[lun][open_am]">
                <option value="">Chiuso</option>
                <option>07:00</option>
                <option>07:30</option>
                <option>08:00</option>
                <option>08:30</option>
                <option>09:00</option>
                <option>09:30</option>
                <option>10:00</option>
              </select>
              <span>-</span>
              <select name="hours[lun][close_am]">
                <option value="">Chiuso</option>
                <option>12:00</option>
                <option>12:30</option>
                <option>13:00</option>
                <option>13:30</option>
                <option>14:00</option>
              </select>

              <!-- POMERIGGIO -->
              <span style="margin: 0 6px">|</span>
              <select name="hours[lun][open_pm]">
                <option value="">Chiuso</option>
                <option>15:30</option>
                <option>16:00</option>
                <option>16:30</option>
                <option>17:00</option>
              </select>
              <span>-</span>
              <select name="hours[lun][close_pm]">
                <option value="">Chiuso</option>
                <option>19:00</option>
                <option>19:30</option>
                <option>20:00</option>
                <option>20:30</option>
                <option>21:00</option>
              </select>
            </div>

            <!-- MARTEDÌ -->
            <div class="weekday-row">
              <span style="width: 90px; display: inline-block">Martedì</span>
              <select name="hours[mar][open_am]">
                <option value="">Chiuso</option>
                <option>07:00</option>
                <option>07:30</option>
                <option>08:00</option>
                <option>08:30</option>
                <option>09:00</option>
                <option>09:30</option>
                <option>10:00</option>
              </select>
              <span>-</span>
              <select name="hours[mar][close_am]">
                <option value="">Chiuso</option>
                <option>12:00</option>
                <option>12:30</option>
                <option>13:00</option>
                <option>13:30</option>
                <option>14:00</option>
              </select>

              <span style="margin: 0 6px">|</span>
              <select name="hours[mar][open_pm]">
                <option value="">Chiuso</option>
                <option>15:30</option>
                <option>16:00</option>
                <option>16:30</option>
                <option>17:00</option>
              </select>
              <span>-</span>
              <select name="hours[mar][close_pm]">
                <option value="">Chiuso</option>
                <option>19:00</option>
                <option>19:30</option>
                <option>20:00</option>
                <option>20:30</option>
                <option>21:00</option>
              </select>
            </div>

            <!-- MERCOLEDÌ -->
            <div class="weekday-row">
              <span style="width: 90px; display: inline-block">Mercoledì</span>
              <select name="hours[mer][open_am]">
                <option value="">Chiuso</option>
                <option>07:00</option>
                <option>07:30</option>
                <option>08:00</option>
                <option>08:30</option>
                <option>09:00</option>
                <option>09:30</option>
                <option>10:00</option>
              </select>
              <span>-</span>
              <select name="hours[mer][close_am]">
                <option value="">Chiuso</option>
                <option>12:00</option>
                <option>12:30</option>
                <option>13:00</option>
                <option>13:30</option>
                <option>14:00</option>
              </select>

              <span style="margin: 0 6px">|</span>
              <select name="hours[mer][open_pm]">
                <option value="">Chiuso</option>
                <option>15:30</option>
                <option>16:00</option>
                <option>16:30</option>
                <option>17:00</option>
              </select>
              <span>-</span>
              <select name="hours[mer][close_pm]">
                <option value="">Chiuso</option>
                <option>19:00</option>
                <option>19:30</option>
                <option>20:00</option>
                <option>20:30</option>
                <option>21:00</option>
              </select>
            </div>

            <!-- GIOVEDÌ -->
            <div class="weekday-row">
              <span style="width: 90px; display: inline-block">Giovedì</span>
              <select name="hours[gio][open_am]">
                <option value="">Chiuso</option>
                <option>07:00</option>
                <option>07:30</option>
                <option>08:00</option>
                <option>08:30</option>
                <option>09:00</option>
                <option>09:30</option>
                <option>10:00</option>
              </select>
              <span>-</span>
              <select name="hours[gio][close_am]">
                <option value="">Chiuso</option>
                <option>12:00</option>
                <option>12:30</option>
                <option>13:00</option>
                <option>13:30</option>
                <option>14:00</option>
              </select>

              <span style="margin: 0 6px">|</span>
              <select name="hours[gio][open_pm]">
                <option value="">Chiuso</option>
                <option>15:30</option>
                <option>16:00</option>
                <option>16:30</option>
                <option>17:00</option>
              </select>
              <span>-</span>
              <select name="hours[gio][close_pm]">
                <option value="">Chiuso</option>
                <option>19:00</option>
                <option>19:30</option>
                <option>20:00</option>
                <option>20:30</option>
                <option>21:00</option>
              </select>
            </div>

            <!-- VENERDÌ -->
            <div class="weekday-row">
              <span style="width: 90px; display: inline-block">Venerdì</span>
              <select name="hours[ven][open_am]">
                <option value="">Chiuso</option>
                <option>07:00</option>
                <option>07:30</option>
                <option>08:00</option>
                <option>08:30</option>
                <option>09:00</option>
                <option>09:30</option>
                <option>10:00</option>
              </select>
              <span>-</span>
              <select name="hours[ven][close_am]">
                <option value="">Chiuso</option>
                <option>12:00</option>
                <option>12:30</option>
                <option>13:00</option>
                <option>13:30</option>
                <option>14:00</option>
              </select>

              <span style="margin: 0 6px">|</span>
              <select name="hours[ven][open_pm]">
                <option value="">Chiuso</option>
                <option>15:30</option>
                <option>16:00</option>
                <option>16:30</option>
                <option>17:00</option>
              </select>
              <span>-</span>
              <select name="hours[ven][close_pm]">
                <option value="">Chiuso</option>
                <option>19:00</option>
                <option>19:30</option>
                <option>20:00</option>
                <option>20:30</option>
                <option>21:00</option>
              </select>
            </div>

            <!-- SABATO -->
            <div class="weekday-row">
              <span style="width: 90px; display: inline-block">Sabato</span>
              <select name="hours[sab][open_am]">
                <option value="">Chiuso</option>
                <option>07:00</option>
                <option>07:30</option>
                <option>08:00</option>
                <option>08:30</option>
                <option>09:00</option>
                <option>09:30</option>
                <option>10:00</option>
              </select>
              <span>-</span>
              <select name="hours[sab][close_am]">
                <option value="">Chiuso</option>
                <option>12:00</option>
                <option>12:30</option>
                <option>13:00</option>
                <option>13:30</option>
                <option>14:00</option>
              </select>

              <span style="margin: 0 6px">|</span>
              <select name="hours[sab][open_pm]">
                <option value="">Chiuso</option>
                <option>15:30</option>
                <option>16:00</option>
                <option>16:30</option>
                <option>17:00</option>
              </select>
              <span>-</span>
              <select name="hours[sab][close_pm]">
                <option value="">Chiuso</option>
                <option>19:00</option>
                <option>19:30</option>
                <option>20:00</option>
                <option>20:30</option>
                <option>21:00</option>
              </select>
            </div>

            <!-- DOMENICA -->
            <div class="weekday-row">
              <span style="width: 90px; display: inline-block">Domenica</span>
              <select name="hours[dom][open_am]">
                <option value="">Chiuso</option>
                <option>07:00</option>
                <option>07:30</option>
                <option>08:00</option>
                <option>08:30</option>
                <option>09:00</option>
                <option>09:30</option>
                <option>10:00</option>
              </select>
              <span>-</span>
              <select name="hours[dom][close_am]">
                <option value="">Chiuso</option>
                <option>12:00</option>
                <option>12:30</option>
                <option>13:00</option>
                <option>13:30</option>
                <option>14:00</option>
              </select>

              <span style="margin: 0 6px">|</span>
              <select name="hours[dom][open_pm]">
                <option value="">Chiuso</option>
                <option>15:30</option>
                <option>16:00</option>
                <option>16:30</option>
                <option>17:00</option>
              </select>
              <span>-</span>
              <select name="hours[dom][close_pm]">
                <option value="">Chiuso</option>
                <option>19:00</option>
                <option>19:30</option>
                <option>20:00</option>
                <option>20:30</option>
                <option>21:00</option>
              </select>
            </div>
          </div>

          <div class="grid" style="margin-top: 20px">
            <label
              >Reparti della farmacia
              <textarea
                name="departments"
                rows="3"
                placeholder="Dermocosmesi; Integratori; Infanzia..."
              ></textarea>
              <small>Lista di reparti presenti in farmacia</small>
            </label>

            <label
              >Descrizione (max 500)
              <textarea
                name="description"
                rows="3"
                maxlength="500"
                placeholder="Breve presentazione della farmacia..."
              ></textarea>
            </label>
          </div>

          <div class="actions">
            <button type="button" class="next">Avanti</button>
          </div>
        </section>

        <section id="step-2" class="form-step">
          <div class="empty-form">
            <button
              type="button"
              onclick="resetCurrentStep('#step-2')"
              class="btn danger"
            >
              Svuota sezione
            </button>
          </div>
          <h2>2) Logo e immagini</h2>
          <div class="grid">
            <label
              >Logo farmacia
              <input
                type="file"
                name="logo"
                accept="image/png,image/svg+xml,image/webp,image/jpeg"
              />
              <div class="upload-count-logo"></div>
              <small>PNG/SVG con sfondo trasparente</small>
              <div class="preview" data-preview="logo"></div>
            </label>

            <label
              >Avatar farmacista
              <input
                type="file"
                name="pharmacist_avatar"
                accept="image/png,image/svg+xml,image/webp,image/jpeg"
              />
              <div class="upload-count-pharmacist_avatar"></div>
              <small>Immagine per generare l'avatar personalizzato</small>
            </label>

            <!-- galleria multipla: file o link -->
            <label
              >Foto farmacia (multiple)
              <input
                type="file"
                id="add_gallery_photo"
                name="photo_gallery[]"
                multiple
                accept="image/*"
              />
              <div class="upload-count-photo_gallery"></div>
              <small
                >Carica foto dell' ingresso, insegna, reparti, interno
                ecc.</small
              >
            </label>

            <div id="gallery_preview" class="gallery-preview"></div>
          </div>

          <div class="actions">
            <button type="button" class="btn muted prev">Indietro</button>
            <button
              type="button"
              class="btn primary next"
              data-validate="step2"
            >
              Avanti
            </button>
          </div>
        </section>

        <!-- STEP 3 -->
        <section id="step-3" class="form-step">
          <div class="empty-form">
            <button
              type="button"
              onclick="resetCurrentStep('#step-3')"
              class="btn danger"
            >
              Svuota sezione
            </button>
          </div>

          <h2>3) Prodotti</h2>
          <p>
            Scegli una delle seguenti opzioni per inviarci l’elenco prodotti. Se
            non hai i dati ora, puoi saltare questo step e inviarli in un
            secondo momento.
          </p>

          <div class="opzioni-prodotti">
            <!-- OPZIONE 1 -->
            <div class="opz-box">
              <h3>✅ Opzione 1 — Usa il nostro CSV modello</h3>
              <p>
                Scarica il file, inserisci i tuoi prodotti e ricaricalo
                compilato.
              </p>

              <a
                class="btn primary"
                href="<?= af_base_url('templates/products_template.csv') ?>"
              >
                Scarica modello CSV prodotti
              </a>

              <small>
                Campi richiesti: nome, prezzo, prezzo_scontato, codice o SKU,
                con_ricetta (sì/no), URL immagine<br />
                <b>Nota:</b> se non hai l’immagine puoi lasciare il campo vuoto.
              </small>

              <label class="file-label">
                Carica CSV compilato
                <input
                  type="file"
                  name="products_csv"
                  accept=".csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                />
                <div class="upload-count-products_csv"></div>
              </label>
            </div>

            <!-- OPZIONE 2 -->
            <div class="opz-box">
              <h3>✅ Opzione 2 — Export gestionale (qualsiasi formato)</h3>
              <p>
                Se il tuo gestionale può esportare i prodotti, carica il file
                così com’è. Ci occuperemo noi del mapping e della conversione.
              </p>

              <label class="file-label">
                Carica file export gestionale
                <input type="file" name="products_export" />
                <div class="upload-count-products_export"></div>
              </label>
              <small>Accettiamo CSV, Excel, PDF, TXT, ZIP, ecc.</small>

              <label class="file-label">
                Carica archivio immagini prodotti (.zip)
                <input type="file" name="products_images_zip" accept=".zip" />
                <div class="upload-count-products_images_zip"></div>
              </label>

            </div>

            <!-- OPZIONE 3 -->
            <div class="opz-box">
              <h3>📦 Promozioni e offerte (opzionale)</h3>
              <p>Se hai promo attive, puoi inviarci un file dedicato.</p>

              <a
                class="btn"
                href="<?= af_base_url('templates/promos_template.csv') ?>"
              >
                Scarica modello CSV promozioni
              </a>

              <small>
                Campi: nome offerta, descrizione, inizio, fine, URL immagine<br />
                Le promo appariranno nella sezione “Promozioni” dell'app.
              </small>

              <label class="file-label">
                Carica CSV promozioni
                <input
                  type="file"
                  name="promos_csv"
                  accept=".csv, .xlsx, .xls, .pdf, .zip, .txt"
                />
                <div class="upload-count-promos_csv"></div>
              </label>
            </div>
          </div>

          <div class="actions">
            <button type="button" class="prev">Indietro</button>
            <button type="button" class="next">Avanti</button>
          </div>
        </section>

        <!-- STEP 4 -->
        <section id="step-4" class="form-step">
          <div class="empty-form">
            <button
              type="button"
              onclick="resetCurrentStep('#step-4')"
              class="btn danger"
            >
              Svuota sezione
            </button>
          </div>

          <h2>4) Servizi offerti</h2>
          <p>
            Puoi inserire alcuni servizi ora. Ulteriori dettagli potranno essere
            aggiunti in seguito.
          </p>

          <div id="servicesRepeater" class="repeater">
            <div class="rep-row">
              <label>
                Nome servizio
                <input name="services[0][name]" />
              </label>

              <label>
                Prezzo (es. €10 o gratuito)
                <input name="services[0][price]" />
              </label>

              <div class="check-inline">
                <input type="checkbox" name="services[0][booking]" value="1" />
                <span>Prenotazione necessaria?</span>
              </div>

              <label>
                Carica immagine del servizio
                <input type="file" name="service_img[]" accept="image/*" class="service-upload" />
                <div class="upload-count-service_img" data-index="0"></div>
              </label>

              <label>
                Descrizione breve
                <textarea name="services[0][desc]" rows="2"></textarea>
              </label>
            </div>
          </div>

          <button type="button" id="addService" class="btn muted">
            + Aggiungi servizio
          </button>

          <div class="actions">
            <button type="button" class="prev">Indietro</button>
            <button type="button" class="next">Avanti</button>
          </div>
        </section>

        <!-- STEP 5 -->
        <section id="step-5" class="form-step">
          <div class="empty-form">
            <button
              type="button"
              onclick="resetCurrentStep('#step-5')"
              class="btn danger"
            >
              Svuota sezione
            </button>
          </div>

          <h2>5) Giornate in farmacia (Eventi)</h2>

          <div id="eventsRepeater" class="repeater">
            <div class="rep-row">
              <label>
                Titolo evento
                <input name="events[0][title]" />
              </label>

              <label>
                Data evento
                <input type="date" name="events[0][date]" />
              </label>

              <label>
                Ora inizio
                <input type="time" name="events[0][start]" />
              </label>

              <label>
                Ora fine
                <input type="time" name="events[0][end]" />
              </label>

              <label>
                Posti disponibili (es. 20)
                <input name="events[0][seats]" />
              </label>

              <div class="check-inline">
                <input type="checkbox" name="events[0][booking]" value="1" />
                <span>Prenotazione necessaria?</span>
              </div>

              <label>
                Carica immagine
                <input
                  type="file"
                  name="event_img[]"
                  class="event-upload"
                  accept="image/*"
                  multiple
                />
                <div class="upload-count-event_img" data-index="0"></div>
              </label>

              <label>
                Descrizione sintetica
                <textarea name="events[0][desc]" rows="2"></textarea>
              </label>
            </div>
          </div>

          <button type="button" id="addEvent" class="btn muted">
            + Aggiungi evento
          </button>

          <div class="actions">
            <button type="button" class="prev">Indietro</button>
            <button type="button" class="next">Avanti</button>
          </div>
        </section>

        <!-- STEP 6 -->
        <section id="step-6" class="form-step">
          <div class="empty-form">
            <button
              type="button"
              onclick="resetCurrentStep('#step-6')"
              class="btn danger"
            >
              Svuota sezione
            </button>
          </div>
          <h2>6) Canali digitali e social</h2>
          <div class="grid">
            <label
              >Sito web
              <input name="site" placeholder="https://www.tuosito.it" />
            </label>
            <label
              >Facebook
              <input name="facebook" placeholder="https://facebook.com/…" />
            </label>
            <label
              >Instagram
              <input name="instagram" placeholder="https://instagram.com/…" />
            </label>
            <label
              >Altri social
              <input
                name="othersocial"
                placeholder="TikTok, YouTube, LinkedIn, …"
              />
            </label>
          </div>
          <div class="actions">
            <button type="button" class="prev">Indietro</button
            ><button type="button" class="next">Avanti</button>
          </div>
        </section>

        <!-- STEP 7 -->
        <section id="step-7" class="form-step">
          <h2>Riepilogo e invio</h2>
          <p>Controlla i dati inseriti. Se tutto è corretto, premi Invia.</p>

          <div id="recap_readable" class="recap-box"></div>

          <div class="actions">
            <button type="button" class="btn muted prev">Indietro</button>
            <button type="submit" class="btn primary">Invia</button>
          </div>
        </section>
      </form>
    </main>

    <footer class="container muted small">© Assistente Farmacia</footer>
    <script src="<?= af_base_url('assets/app.js') ?>"></script>
  </body>
</html>
