<?php
/*
Template Name: Dashboard Dealer
*/

if (!is_user_logged_in()) {if (!defined('ABSPATH')) {
    wp_safe_redirect(wp_login_url());
    exit;
}

get_header();

$user = wp_get_current_user();
$user_roles = (array) $user->roles;
?>

<style>
.dashboard-dealer-wrapper {
  background: #f4f7f9;
  min-height: 70vh;
  padding: 50px 20px;
}

.dashboard-dealer-container {
  max-width: 1200px;
  margin: 0 auto;
}

.dashboard-dealer-hero {
  background: linear-gradient(135deg, #003a5d, #006aa1);
  color: #ffffff;
  padding: 35px;
  border-radius: 16px;
  margin-bottom: 30px;
}

.dashboard-dealer-hero h1 {
  margin: 0 0 10px 0;
  color: #ffffff;
  font-size: 32px;
}

.dashboard-dealer-hero p {
  margin: 0;
  font-size: 16px;
  color: #ffffff;
}

.dashboard-dealer-section-title {
  margin: 35px 0 15px 0;
  font-size: 22px;
  color: #003a5d;
}

.dashboard-dealer-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 20px;
}

.dashboard-dealer-card {
  display: block;
  background: #ffffff;
  padding: 25px;
  border-radius: 14px;
  text-decoration: none;
  color: #003a5d;
  border: 1px solid #dce5ea;
  box-shadow: 0 4px 14px rgba(0,0,0,0.04);
  transition: all 0.2s ease;
}

.dashboard-dealer-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 22px rgba(0,0,0,0.08);
  border-color: #006aa1;
  color: #003a5d;
  text-decoration: none;
}

.dashboard-dealer-card-icon {
  font-size: 32px;
  display: block;
  margin-bottom: 12px;
}

.dashboard-dealer-card-title {
  display: block;
  font-size: 18px;
  font-weight: 700;
  margin-bottom: 8px;
}

.dashboard-dealer-card-text {
  display: block;
  font-size: 14px;
  color: #52616b;
  line-height: 1.4;
}

.dashboard-dealer-badge {
  display: inline-block;
  background: #006aa1;
  color: #ffffff;
  padding: 4px 8px;
  border-radius: 999px;
  font-size: 12px;
  margin-left: 6px;
  vertical-align: middle;
}

.dashboard-dealer-premium {
  margin-top: 35px;
  padding: 25px;
  background: #eef6fb;
  border: 1px solid #cfe3ef;
  border-radius: 16px;
}

.dashboard-dealer-footer-actions {
  margin-top: 35px;
  display: flex;
  gap: 15px;
  flex-wrap: wrap;
}

.dashboard-dealer-small-link {
  color: #006aa1;
  text-decoration: none;
  font-weight: 600;
}

.dashboard-dealer-small-link:hover {
  text-decoration: underline;
}
</style>

<div class="dashboard-dealer-wrapper">
  <div class="dashboard-dealer-container">

    <div class="dashboard-dealer-hero">
      <h1>Area Riservata Dealer</h1>
      <p>
        Benvenuto <strong><?php echo esc_html($user->display_name); ?></strong>.
        Da questa pagina puoi accedere al materiale tecnico e commerciale riservato.
      </p>
    </div>

    <h2 class="dashboard-dealer-section-title">Accessi rapidi</h2>

    <div class="dashboard-dealer-grid">

      <a class="dashboard-dealer-card" href="<?php echo esc_url(site_url('/customer-area/files/')); ?>">
        <span class="dashboard-dealer-card-icon">📁</span>
        <span class="dashboard-dealer-card-title">Materiale Tecnico</span>
        <span class="dashboard-dealer-card-text">
          Manuali, schede tecniche, documentazione di prodotto e materiale di supporto.
        </span>
      </a>

      <a class="dashboard-dealer-card" href="<?php echo esc_url(site_url('/customer-area/documents/')); ?>">
        <span class="dashboard-dealer-card-icon">📄</span>
        <span class="dashboard-dealer-card-title">Documentazione Commerciale</span>
        <span class="dashboard-dealer-card-text">
          Cataloghi, brochure, presentazioni e materiale commerciale per la rete dealer.
        </span>
      </a>

      <a class="dashboard-dealer-card" href="<?php echo esc_url(site_url('/customer-area/messages/')); ?>">
        <span class="dashboard-dealer-card-icon">📢</span>
        <span class="dashboard-dealer-card-title">
          Comunicazioni
          <span class="dashboard-dealer-badge">Nuovo</span>
        </span>
        <span class="dashboard-dealer-card-text">
          Avvisi, aggiornamenti e comunicazioni riservate alla rete.
        </span>
      </a>

      <a class="dashboard-dealer-card" href="<?php echo esc_url(site_url('/customer-area/account/')); ?>">
        <span class="dashboard-dealer-card-icon">⚙️</span>
        <span class="dashboard-dealer-card-title">Profilo Dealer</span>
        <span class="dashboard-dealer-card-text">
          Gestisci i dati del tuo account e le informazioni del profilo.
        </span>
      </a>

    </div>

    <?php if (in_array('dealer_premium', $user_roles, true)) : ?>

      <div class="dashboard-dealer-premium">

        <h2 class="dashboard-dealer-section-title">Contenuti Premium</h2>

        <div class="dashboard-dealer-grid">

          <a class="dashboard-dealer-card" href="<?php echo esc_url(site_url('/customer-area/files/')); ?>">
            <span class="dashboard-dealer-card-icon">⭐</span>
            <span class="dashboard-dealer-card-title">Listini Riservati</span>
            <span class="dashboard-dealer-card-text">
              Accesso a listini, condizioni commerciali e documentazione riservata.
            </span>
          </a>

          <a class="dashboard-dealer-card" href="<?php echo esc_url(site_url('/customer-area/documents/')); ?>">
            <span class="dashboard-dealer-card-icon">📊</span>
            <span class="dashboard-dealer-card-title">Schede Tecniche Avanzate</span>
            <span class="dashboard-dealer-card-text">
              Materiale tecnico avanzato dedicato ai dealer con accesso premium.
            </span>
          </a>

        </div>

      </div>

    <?php endif; ?>

    <div class="dashboard-dealer-footer-actions">
      <a class="dashboard-dealer-small-link" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
        Esci dall’area riservata
      </a>
    </div>

  </div>
</div>

<?php
get_footer();
    exit;
}

