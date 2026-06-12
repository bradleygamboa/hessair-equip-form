<?php defined( 'ABSPATH' ) || exit; ?>
<?php
/**
 * $config is passed from hessqf_shortcode():
 *   systems     => array of normalized systems
 *   tableCols   => array of [ key => [ label, visible ] ]
 *   cardFields  => array of [ key => [ label, visible ] ]
 *   taxDefault  => string (e.g. "8.25")
 *   yearMode    => 'all' | 'latest'
 */
$has_data = ! empty( $config['systems'] );
?>

<div class="hessqf-form-wrap">

  <?php if ( ! $has_data ) : ?>
    <div class="hessqf-alert hessqf-alert-warning hessqf-show" style="margin-bottom:16px;">
      <strong>No product data loaded.</strong> An administrator needs to configure the Google Sheets URL in
      <em>Settings → Hess Quote Form</em>.
    </div>
  <?php endif; ?>

  <!-- ── Step Indicator ── -->
  <div class="hessqf-step-indicator" id="hessqfStepIndicator">
    <div class="hessqf-step-pill active" id="hessqfStep1Pill"
         onclick="event.preventDefault(); event.stopPropagation(); return false;">
      <div class="hessqf-step-number">1</div>
      <span>Product Selection</span>
    </div>
    <div class="hessqf-step-connector"></div>
    <div class="hessqf-step-pill" id="hessqfStep2Pill"
         onclick="event.preventDefault(); event.stopPropagation(); return false;">
      <div class="hessqf-step-number">2</div>
      <span>Your Details</span>
    </div>
  </div>

  <!-- ══════════════════════════════════════
       STEP 1 — Product Selection
  ══════════════════════════════════════ -->
  <div class="hessqf-step-panel active" id="hessqfStepPanel1">

    <!-- Filters -->
    <div class="hessqf-card">
      <div class="hessqf-card-title">Filter Products</div>
      <div class="hessqf-filters-grid">
        <div class="hessqf-filter-group">
          <label>Brand</label>
          <select id="hessqfFilterBrand"><option value="">All Brands</option></select>
        </div>
        <div class="hessqf-filter-group">
          <label>System Type</label>
          <select id="hessqfFilterSystem"><option value="">All Types</option></select>
        </div>
        <div class="hessqf-filter-group">
          <label>Capacity (Tons)</label>
          <select id="hessqfFilterCapacity"><option value="">All Capacities</option></select>
        </div>
        <div class="hessqf-filter-actions">
          <button type="button" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm" id="hessqfFilterSearchBtn">Search</button>
          <button type="button" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm"   id="hessqfFilterClearBtn">Clear</button>
        </div>
      </div>
    </div>

    <!-- Product Table -->
    <div class="hessqf-card">
      <div class="hessqf-card-title">Available Systems</div>
      <div id="hessqfAlertNoResults" class="hessqf-alert hessqf-alert-warning">No products match the selected filters. Try adjusting your search.</div>
      <div class="hessqf-table-wrapper">
        <table id="hessqfProductTable">
          <thead><tr id="hessqfProductTableHead"></tr></thead>
          <tbody id="hessqfProductTableBody"></tbody>
        </table>
      </div>
      <div id="hessqfFinancingInfo" style="display:none;">
        <div class="hessqf-financing-question">
          <label>Will customer use 0% interest financing?</label>
          <div class="hessqf-radio-group">
            <label class="hessqf-radio-option"><input type="radio" name="hessqfFinancing0pct" id="hessqfFinancing0pctYes" value="Yes" /> Yes</label>
            <label class="hessqf-radio-option"><input type="radio" name="hessqfFinancing0pct" id="hessqfFinancing0pctNo" value="No" /> No</label>
          </div>
        </div>
        <div class="hessqf-financing-disclaimer">0% Financing available with approved credit.</div>
        <div class="hessqf-financing-disclaimer">*Estimate based on 9.99% APR financing over 10 years with a minimum payment.</div>
      </div>
    </div>

    <!-- Compare Units (top) + Value Packages (bottom) -->
    <div class="hessqf-card hessqf-tier-cards-section" id="hessqfTierCardsSection" style="display:none;">
      <div class="hessqf-card-title">Compare Units</div>
      <div class="hessqf-card-sub" style="font-size:0.85rem;color:#666;margin:-6px 0 14px;">Click "Compare" on as many units as you'd like above. Adjust pricing per unit, then pick one to continue.</div>
      <div class="hessqf-tier-cards-grid" id="hessqfTierCardsGrid"></div>

      <div id="hessqfPackageSection" style="display:none;">
        <img src="<?php echo esc_url( HESSQF_URL . 'assets/images/hesserized-logo.png' ); ?>" alt="HESSeRized (just right)" class="hessqf-hesserized-logo" style="display:block;margin:28px 0 6px;max-width:260px;height:auto;" />
        <div class="hessqf-card-title">Choose Your Hesserized&trade; Promotional System</div>
        <div class="hessqf-card-sub" style="font-size:0.85rem;color:#666;margin:-6px 0 14px;">Select the warranty &amp; service package that's right for you.</div>
        <div class="hessqf-tier-cards-grid" id="hessqfPackageGrid"></div>
      </div>
    </div>

    <!-- Cost Adjustments + Selection Bar + Next -->
    <div class="hessqf-card" id="hessqfSelectionBarSection" style="display:none;">
      <div class="hessqf-card-title" style="font-size:0.85rem;">Adjust Pricing for Selected Unit</div>
      <div class="hqf-adj-grid" style="margin-bottom:18px;">
        <div class="hessqf-form-group hqf-options-group">
          <label>Options</label>
          <div class="hqf-options-input-row">
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfAdjOptions" class="hqf-adj-input" placeholder="$0" disabled readonly />
            <button type="button" id="hessqfAddOptionBtn" class="hqf-add-option-btn" disabled aria-label="Add an option">+</button>
          </div>
          <div class="hqf-add-option-form" id="hessqfAddOptionForm" style="display:none;">
            <input type="text" autocomplete="off" id="hessqfNewOptionLabel" class="hqf-add-option-label" placeholder="Option name" />
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfNewOptionCost" class="hqf-add-option-cost" placeholder="$0" />
            <button type="button" id="hessqfNewOptionAdd" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm">Add</button>
            <button type="button" id="hessqfNewOptionCancel" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm">Cancel</button>
          </div>
          <div class="hqf-options-list" id="hessqfOptionsList"></div>
        </div>
        <div class="hessqf-form-group">
          <label>Procurement/Labor/Materials/Other</label>
          <div class="hqf-options-input-row">
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfAdjInstallation" class="hqf-adj-input" placeholder="$0" disabled readonly />
            <button type="button" id="hessqfAddInstallationBtn" class="hqf-add-option-btn" disabled aria-label="Add an item">+</button>
          </div>
          <div class="hqf-add-option-form" id="hessqfAddInstallationForm" style="display:none;">
            <input type="text" autocomplete="off" id="hessqfNewInstallationLabel" class="hqf-add-option-label" placeholder="Description" />
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfNewInstallationCost" class="hqf-add-option-cost" placeholder="$0" />
            <button type="button" id="hessqfNewInstallationAdd" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm">Add</button>
            <button type="button" id="hessqfNewInstallationCancel" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm">Cancel</button>
          </div>
          <div class="hqf-options-list" id="hessqfInstallationList"></div>
        </div>
        <div class="hessqf-form-group">
          <label>Down Payment/Cash/Credit Card</label>
          <div class="hqf-options-input-row">
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfAdjDown" class="hqf-adj-input" placeholder="$0" disabled />
            <button type="button" id="hessqfAddDownNoteBtn" class="hqf-add-option-btn" disabled aria-label="Add a note">+</button>
          </div>
          <div class="hqf-add-option-form" id="hessqfAddDownNoteForm" style="display:none;">
            <input type="text" autocomplete="off" id="hessqfNewDownNote" class="hqf-add-option-label" placeholder="Notes" style="flex:1 1 100%;" />
            <button type="button" id="hessqfNewDownNoteAdd" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm">Add</button>
            <button type="button" id="hessqfNewDownNoteCancel" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm">Cancel</button>
          </div>
          <div class="hqf-options-list" id="hessqfDownNotesList"></div>
        </div>
        <div class="hessqf-form-group">
          <label>Trade In</label>
          <div class="hqf-options-input-row">
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfAdjTradeIn" class="hqf-adj-input" placeholder="$0" disabled />
            <button type="button" id="hessqfAddTradeInNoteBtn" class="hqf-add-option-btn" disabled aria-label="Add a note">+</button>
          </div>
          <div class="hqf-add-option-form" id="hessqfAddTradeInNoteForm" style="display:none;">
            <input type="text" autocomplete="off" id="hessqfNewTradeInNote" class="hqf-add-option-label" placeholder="Notes" style="flex:1 1 100%;" />
            <button type="button" id="hessqfNewTradeInNoteAdd" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm">Add</button>
            <button type="button" id="hessqfNewTradeInNoteCancel" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm">Cancel</button>
          </div>
          <div class="hqf-options-list" id="hessqfTradeInNotesList"></div>
        </div>
      </div>

      <div class="hessqf-selection-bar" style="margin-bottom:16px;">
        <div><div class="hessqf-sel-label">Selected Unit</div><div class="hessqf-sel-value" id="hessqfSelectedUnitDisplay">—</div></div>
        <div><div class="hessqf-sel-label">Selected Package</div><div class="hessqf-sel-value" id="hessqfSelectedTierDisplay">—</div></div>
        <div><div class="hessqf-sel-label">Total Investment</div><div class="hessqf-sel-value" id="hessqfSelectedPriceDisplay">—</div></div>
        <div><div class="hessqf-sel-label">SEER2</div><div class="hessqf-sel-value" id="hessqfSelectedSeer2Display">—</div></div>
      </div>

      <div id="hessqfAlertNoSelection" class="hessqf-alert hessqf-alert-warning">Please choose a unit and a value package before continuing.</div>

      <div class="hessqf-step-nav">
        <button type="button" class="hessqf-btn hessqf-btn-pink hessqf-btn-lg" id="hessqfGoToStep2Btn">Continue &rarr;</button>
      </div>
    </div>

  </div><!-- /stepPanel1 -->

  <!-- ══════════════════════════════════════
       STEP 2 — Contact Information
  ══════════════════════════════════════ -->
  <div class="hessqf-step-panel" id="hessqfStepPanel2">

    <div class="hessqf-back-nav">
      <button type="button" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm" id="hessqfBackBtn">&larr; Back to Product Selection</button>
    </div>

    <div class="hessqf-quote-number-banner">
      <div>
        <div class="hessqf-qn-label">Quote Number</div>
        <div class="hessqf-qn-value" id="hessqfQuoteNumberDisplay">—</div>
      </div>
      <div style="font-size:0.8rem;opacity:0.65;">Generated automatically — include this number in any follow-up communications.</div>
    </div>

    <div class="hessqf-card">
      <div class="hessqf-card-title">Quote Summary</div>
      <div class="hessqf-quote-summary">
        <h3>Selected System</h3>
        <div class="hessqf-summary-rows" id="hessqfStep2Summary"></div>
      </div>
      <div class="hessqf-financing-disclaimer">0% Financing available with approved credit.</div>
      <div class="hessqf-financing-disclaimer">*Estimate based on 9.99% APR financing over 10 years with a minimum payment.</div>

      <div id="hessqfAlertSubmitError" class="hessqf-alert hessqf-alert-error"></div>

      <div class="hessqf-form-grid">
        <div class="hessqf-form-group">
          <label>Full Name <span class="hessqf-required">*</span></label>
          <input type="text" id="hessqfFieldName" placeholder="Jane Smith" />
          <span class="hessqf-field-error" id="hessqfErrName"></span>
        </div>
        <div class="hessqf-form-group">
          <label>Phone Number <span class="hessqf-required">*</span></label>
          <input type="tel" id="hessqfFieldPhone" placeholder="(555) 867-5309" />
          <span class="hessqf-field-error" id="hessqfErrPhone"></span>
        </div>
        <div class="hessqf-form-group">
          <label>Email Address <span class="hessqf-required">*</span></label>
          <input type="email" id="hessqfFieldEmail" placeholder="jane@example.com" />
          <span class="hessqf-field-error" id="hessqfErrEmail"></span>
        </div>
        <div class="hessqf-form-group">
          <label>When would you like to schedule?</label>
          <select id="hessqfFieldSchedule">
            <option value="">— Select timing —</option>
            <option>Next 24 hours</option>
            <option>This week</option>
            <option>Next week</option>
            <option>This month</option>
          </select>
          <span class="hessqf-field-error" id="hessqfErrSchedule"></span>
        </div>
        <div class="hessqf-form-group hessqf-full-width">
          <label>Other Requests or Comments</label>
          <textarea id="hessqfFieldComments" placeholder="Any special instructions, access notes, or questions..."></textarea>
        </div>
        <div class="hessqf-form-group hessqf-full-width">
          <label>Signature <span class="hessqf-optional">(optional)</span></label>
          <div class="hessqf-signature-wrap">
            <canvas id="hessqfSignaturePad" class="hessqf-signature-canvas" aria-label="Signature pad — sign with your mouse or finger"></canvas>
            <button type="button" id="hessqfSignatureClear" class="hessqf-signature-clear">Clear</button>
          </div>
          <input type="hidden" id="hessqfFieldSignature" />
        </div>
      </div>

      <div class="hessqf-step-nav" style="margin-top:24px;">
        <button type="button" class="hessqf-btn hessqf-btn-pink hessqf-btn-lg" id="hessqfSubmitBtn">Submit Quote &rarr;</button>
      </div>
    </div>

  </div><!-- /stepPanel2 -->

  <!-- Confirmation Panel -->
  <div class="hessqf-confirmation-panel" id="hessqfConfirmationPanel">
    <div class="hessqf-confirm-icon">&#10003;</div>
    <div class="hessqf-confirm-title">Quote Submitted!</div>
    <div class="hessqf-confirm-sub">Your quote request has been received. A team member will follow up shortly.</div>
    <div style="display:inline-block;background:#f4f5f7;border:1px solid #c6d8ee;border-radius:6px;padding:8px 20px;margin-bottom:24px;">
      <span style="font-size:0.78rem;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#666;">Quote Number&nbsp;&nbsp;</span>
      <span style="font-size:1.05rem;font-weight:700;color:#c0457a;letter-spacing:1px;" id="hessqfConfirmQuoteNumber"></span>
    </div>
    <p class="hessqf-confirm-email-note" id="hessqfConfirmEmailNote"></p>
    <div class="hessqf-confirm-details" id="hessqfConfirmDetails"></div>
  </div>

  <!-- Inline JSON: data + config -->
  <script type="application/json" id="hessqfSystemsData"><?php echo wp_json_encode( $config['systems'] ); ?></script>
  <script type="application/json" id="hessqfConfigData"><?php echo wp_json_encode( [
      'tableCols'  => $config['tableCols'],
      'cardFields' => $config['cardFields'],
      'taxDefault' => $config['taxDefault'],
      'yearMode'   => $config['yearMode'],
  ] ); ?></script>

</div><!-- /.hessqf-form-wrap -->
