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
      <em>Settings → Hesserized Quotes</em>.
    </div>
  <?php endif; ?>

  <!-- ── Hess Associate & Customer Information ── -->
  <div class="hessqf-card" id="hessqfeTopInfoCard">
    <div class="hessqf-card-title">Hess Associate</div>
    <div class="hessqf-form-grid">
      <div class="hessqf-form-group hessqf-full-width">
        <label>Associate Full Name <span class="hessqf-required">*</span></label>
        <input type="text" id="hessqfeFieldAssociate" placeholder="John Doe" />
        <span class="hessqf-field-error" id="hessqfeErrAssociate"></span>
      </div>
    </div>

    <div class="hessqf-card-title" style="margin-top:18px;">Customer Information</div>
    <div class="hessqf-form-grid">
      <div class="hessqf-full-width hessqf-contact-row">
        <div class="hessqf-form-group">
          <label>Full Name <span class="hessqf-required">*</span></label>
          <input type="text" id="hessqfeFieldCustomerName" placeholder="Jane Smith" />
          <span class="hessqf-field-error" id="hessqfeErrCustomerName"></span>
        </div>
        <div class="hessqf-form-group">
          <label>Email Address <span class="hessqf-required">*</span></label>
          <input type="email" id="hessqfeFieldCustomerEmail" placeholder="jane@example.com" />
          <span class="hessqf-field-error" id="hessqfeErrCustomerEmail"></span>
        </div>
        <div class="hessqf-form-group">
          <label>Phone Number <span class="hessqf-required">*</span></label>
          <input type="tel" id="hessqfeFieldCustomerPhone" placeholder="(555) 867-5309" />
          <span class="hessqf-field-error" id="hessqfeErrCustomerPhone"></span>
        </div>
      </div>
      <div class="hessqf-form-group hessqf-full-width">
        <label>Address <span class="hessqf-required">*</span></label>
        <input type="text" id="hessqfeFieldCustomerAddress" placeholder="123 Main St, Brownsville, TX 78520" />
        <span class="hessqf-field-error" id="hessqfeErrCustomerAddress"></span>
      </div>
    </div>

    <div class="hessqf-card-title" style="margin-top:18px;">Existing Outdoor Unit</div>
    <div class="hessqf-form-grid">
      <div class="hessqf-full-width hessqf-contact-row">
        <div class="hessqf-form-group">
          <label>Brand</label>
          <input type="text" id="hessqfeFieldExistingBrand" placeholder="e.g. Carrier" />
        </div>
        <div class="hessqf-form-group">
          <label>Model Number/Size</label>
          <input type="text" id="hessqfeFieldExistingModel" placeholder="Model #" />
        </div>
        <div class="hessqf-form-group">
          <label>Serial Number</label>
          <input type="text" id="hessqfeFieldExistingSerial" placeholder="Serial #" />
        </div>
      </div>
      <div class="hessqf-form-group hessqf-full-width" style="margin-top:4px;">
        <label style="display:block;margin-bottom:6px;font-weight:600;">Unit Location</label>
        <div style="display:flex;gap:20px;align-items:center;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="radio" name="hessqfeExistingAtticCloset" id="hessqfeFieldExistingAtticClosetNone" value="" checked /> None
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="radio" name="hessqfeExistingAtticCloset" id="hessqfeFieldExistingAtticClosetAttic" value="Attic Unit" /> Attic Unit
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="radio" name="hessqfeExistingAtticCloset" id="hessqfeFieldExistingAtticClosetCloset" value="Closet Unit" /> Closet Unit
          </label>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Step Indicator ── -->
  <div class="hessqf-step-indicator" id="hessqfeStepIndicator">
    <div class="hessqf-step-pill active" id="hessqfeStep1Pill"
         onclick="event.preventDefault(); event.stopPropagation(); return false;">
      <div class="hessqf-step-number">1</div>
      <span>Product Selection</span>
    </div>
    <div class="hessqf-step-connector"></div>
    <div class="hessqf-step-pill" id="hessqfeStep2Pill"
         onclick="event.preventDefault(); event.stopPropagation(); return false;">
      <div class="hessqf-step-number">2</div>
      <span>Your Details</span>
    </div>
  </div>

  <!-- ══════════════════════════════════════
       STEP 1 — Product Selection
  ══════════════════════════════════════ -->
  <div class="hessqf-step-panel active" id="hessqfeStepPanel1">

    <!-- Filters -->
    <div class="hessqf-card">
      <div class="hessqf-card-title">Filter Products</div>
      <div class="hessqf-filters-grid">
        <div class="hessqf-filter-group">
          <label>Brand</label>
          <select id="hessqfeFilterBrand"><option value="">All Brands</option></select>
        </div>
        <div class="hessqf-filter-group">
          <label>System Type</label>
          <select id="hessqfeFilterSystem"><option value="">All Types</option></select>
        </div>
        <div class="hessqf-filter-group">
          <label>Capacity (Tons)</label>
          <select id="hessqfeFilterCapacity"><option value="">All Capacities</option></select>
        </div>
        <div class="hessqf-filter-actions">
          <button type="button" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm" id="hessqfeFilterSearchBtn">Search</button>
          <button type="button" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm"   id="hessqfeFilterClearBtn">Clear</button>
        </div>
      </div>
    </div>

    <!-- Product Table -->
    <div class="hessqf-card">
      <div class="hessqf-card-title">Available Systems</div>
      <div id="hessqfeAlertNoResults" class="hessqf-alert hessqf-alert-warning">No products match the selected filters. Try adjusting your search.</div>
      <div class="hessqf-table-wrapper">
        <table id="hessqfeProductTable">
          <thead><tr id="hessqfeProductTableHead"></tr></thead>
          <tbody id="hessqfeProductTableBody"></tbody>
        </table>
      </div>
      <div id="hessqfeFinancingInfo" style="display:none;">
        <div class="hessqf-financing-question">
          <label>Will customer use 0% interest financing?</label>
          <div class="hessqf-radio-group">
            <label class="hessqf-radio-option"><input type="radio" name="hessqfeFinancing0pct" id="hessqfeFinancing0pctYes" value="Yes" /> Yes</label>
            <label class="hessqf-radio-option"><input type="radio" name="hessqfeFinancing0pct" id="hessqfeFinancing0pctNo" value="No" /> No</label>
          </div>
        </div>

        <div class="hessqf-financing-question" id="hessqfeFinancingTermRow" style="display:none;">
          <label>How Many Payments:</label>
          <div class="hessqf-radio-group">
            <label class="hessqf-radio-option"><input type="radio" name="hessqfeFinancingTerm" value="12 mos." /> 12 mos.</label>
            <label class="hessqf-radio-option"><input type="radio" name="hessqfeFinancingTerm" value="24 mos." /> 24 mos.</label>
            <label class="hessqf-radio-option"><input type="radio" name="hessqfeFinancingTerm" value="36 mos." /> 36 mos.</label>
            <label class="hessqf-radio-option"><input type="radio" name="hessqfeFinancingTerm" value="48 mos." /> 48 mos.</label>
            <label class="hessqf-radio-option"><input type="radio" name="hessqfeFinancingTerm" value="60 mos." /> 60 mos.</label>
          </div>
        </div>

        <div class="hessqf-financing-question" id="hessqfeFinancing999Row" style="display:none;">
          <label>Will customer want 9.99% financing?</label>
          <div class="hessqf-radio-group">
            <label class="hessqf-radio-option"><input type="radio" name="hessqfeFinancing999" id="hessqfeFinancing999Yes" value="Yes" /> Yes</label>
            <label class="hessqf-radio-option"><input type="radio" name="hessqfeFinancing999" id="hessqfeFinancing999No" value="No" /> No</label>
          </div>
        </div>
        <div class="hessqf-financing-disclaimer" id="hessqfeFinancing999Disclaimer" style="display:none;">*9.99% APR for 120 equal monthly payments.</div>

        <div class="hessqf-financing-approved">Financing with approved credit.</div>
        <div class="hessqf-financing-disclaimer">*Estimate based on 9.99% APR financing over 10 years with a minimum payment.</div>
      </div>
    </div>

    <!-- Compare Units (top) + Value Packages (bottom) -->
    <div class="hessqf-card hessqf-tier-cards-section" id="hessqfeTierCardsSection" style="display:none;">
      <div class="hessqf-card-title">Compare Units</div>
      <div class="hessqf-card-sub" style="font-size:0.85rem;color:#666;margin:-6px 0 14px;">Click "Compare" on as many units as you'd like above. Adjust pricing per unit, then pick one to continue.</div>
      <div class="hessqf-tier-cards-grid" id="hessqfeTierCardsGrid"></div>

    </div>

    <!-- Cost Adjustments + Selection Bar + Next -->
    <div class="hessqf-card" id="hessqfeSelectionBarSection" style="display:none;">
      <div class="hessqf-card-title" style="font-size:0.85rem;">Adjust Pricing for Selected Unit</div>
      <div class="hqf-adj-grid" style="margin-bottom:18px;">
        <div class="hessqf-form-group hqf-options-group">
          <label>Options</label>
          <div class="hqf-options-input-row">
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfeAdjOptions" class="hqf-adj-input" placeholder="$0" disabled readonly />
            <button type="button" id="hessqfeAddOptionBtn" class="hqf-add-option-btn" disabled aria-label="Add an option">+</button>
          </div>
          <div class="hqf-add-option-form" id="hessqfeAddOptionForm" style="display:none;">
            <input type="text" autocomplete="off" id="hessqfeNewOptionLabel" class="hqf-add-option-label" placeholder="Option name" />
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfeNewOptionCost" class="hqf-add-option-cost" placeholder="$0" />
            <button type="button" id="hessqfeNewOptionAdd" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm">Add</button>
            <button type="button" id="hessqfeNewOptionCancel" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm">Cancel</button>
          </div>
          <div class="hqf-options-list" id="hessqfeOptionsList"></div>
        </div>
        <div class="hessqf-form-group">
          <label>Procurement/Labor/Materials</label>
          <div class="hqf-options-input-row">
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfeAdjInstallation" class="hqf-adj-input" placeholder="$0" disabled readonly />
            <button type="button" id="hessqfeAddInstallationBtn" class="hqf-add-option-btn" disabled aria-label="Add an item">+</button>
          </div>
          <div class="hqf-add-option-form" id="hessqfeAddInstallationForm" style="display:none;">
            <input type="text" autocomplete="off" id="hessqfeNewInstallationLabel" class="hqf-add-option-label" placeholder="Description" />
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfeNewInstallationCost" class="hqf-add-option-cost" placeholder="$0" />
            <button type="button" id="hessqfeNewInstallationAdd" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm">Add</button>
            <button type="button" id="hessqfeNewInstallationCancel" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm">Cancel</button>
          </div>
          <div class="hqf-options-list" id="hessqfeInstallationList"></div>
        </div>
        <div class="hessqf-form-group">
          <label>Down Payment/Cash/Credit Card</label>
          <div class="hqf-options-input-row">
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfeAdjDown" class="hqf-adj-input" placeholder="$0" disabled />
            <button type="button" id="hessqfeAddDownNoteBtn" class="hqf-add-option-btn" disabled aria-label="Add a note">+</button>
          </div>
          <div class="hqf-add-option-form" id="hessqfeAddDownNoteForm" style="display:none;">
            <input type="text" autocomplete="off" id="hessqfeNewDownNote" class="hqf-add-option-label" placeholder="Notes" style="flex:1 1 100%;" />
            <button type="button" id="hessqfeNewDownNoteAdd" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm">Add</button>
            <button type="button" id="hessqfeNewDownNoteCancel" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm">Cancel</button>
          </div>
          <div class="hqf-options-list" id="hessqfeDownNotesList"></div>
        </div>
        <div class="hessqf-form-group">
          <label>Other</label>
          <div class="hqf-options-input-row">
            <input type="text" inputmode="decimal" autocomplete="off" id="hessqfeAdjTradeIn" class="hqf-adj-input" placeholder="$0" disabled />
            <button type="button" id="hessqfeAddTradeInNoteBtn" class="hqf-add-option-btn" disabled aria-label="Add a note">+</button>
          </div>
          <div class="hqf-add-option-form" id="hessqfeAddTradeInNoteForm" style="display:none;">
            <input type="text" autocomplete="off" id="hessqfeNewTradeInNote" class="hqf-add-option-label" placeholder="Notes" style="flex:1 1 100%;" />
            <button type="button" id="hessqfeNewTradeInNoteAdd" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm">Add</button>
            <button type="button" id="hessqfeNewTradeInNoteCancel" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm">Cancel</button>
          </div>
          <div class="hqf-options-list" id="hessqfeTradeInNotesList"></div>
        </div>
      </div>

      <div class="hessqf-selection-bar" style="margin-bottom:16px;">
        <div><div class="hessqf-sel-label">Selected Unit</div><div class="hessqf-sel-value" id="hessqfeSelectedUnitDisplay">—</div></div>
        <div><div class="hessqf-sel-label">Selected Package</div><div class="hessqf-sel-value" id="hessqfeSelectedTierDisplay">—</div></div>
        <div><div class="hessqf-sel-label">Total Investment</div><div class="hessqf-sel-value" id="hessqfeSelectedPriceDisplay">—</div></div>
        <div><div class="hessqf-sel-label">Amount Financed</div><div class="hessqf-sel-value" id="hessqfeSelectedAmountFinancedDisplay">—</div></div>
        <div><div class="hessqf-sel-label">SEER2</div><div class="hessqf-sel-value" id="hessqfeSelectedSeer2Display">—</div></div>
      </div>

      <div id="hessqfeAlertNoSelection" class="hessqf-alert hessqf-alert-warning">Please choose a unit and a value package before continuing.</div>

      <div class="hessqf-step-nav">
        <button type="button" class="hessqf-btn hessqf-btn-pink hessqf-btn-lg" id="hessqfeGoToStep2Btn">Continue &rarr;</button>
      </div>
    </div>

  </div><!-- /stepPanel1 -->

  <!-- ══════════════════════════════════════
       STEP 2 — Contact Information
  ══════════════════════════════════════ -->
  <div class="hessqf-step-panel" id="hessqfeStepPanel2">

    <div class="hessqf-back-nav">
      <button type="button" class="hessqf-btn hessqf-btn-ghost hessqf-btn-sm" id="hessqfeBackBtn">&larr; Back to Product Selection</button>
    </div>

    <div class="hessqf-quote-number-banner">
      <div>
        <div class="hessqf-qn-label">Quote Number</div>
        <div class="hessqf-qn-value" id="hessqfeQuoteNumberDisplay">—</div>
      </div>
      <div style="font-size:0.8rem;opacity:0.65;">Generated automatically — include this number in any follow-up communications.</div>
    </div>

    <div class="hessqf-card">
      <div class="hessqf-card-title">Quote Summary</div>
      <div class="hessqf-quote-summary">
        <h3>Selected System</h3>
        <div class="hessqf-summary-rows" id="hessqfeStep2Summary"></div>
      </div>
      <div class="hessqf-financing-approved">Financing with approved credit.</div>
      <div class="hessqf-financing-disclaimer">*Estimate based on 9.99% APR financing over 10 years with a minimum payment.</div>

      <div id="hessqfeAlertSubmitError" class="hessqf-alert hessqf-alert-error"></div>

      <div class="hessqf-form-grid">
        <div class="hessqf-full-width hessqf-contact-row">
          <div class="hessqf-form-group">
            <label>Full Name <span class="hessqf-required">*</span></label>
            <input type="text" id="hessqfeFieldName" placeholder="Jane Smith" />
            <span class="hessqf-field-error" id="hessqfeErrName"></span>
          </div>
          <div class="hessqf-form-group">
            <label>Email Address <span class="hessqf-required">*</span></label>
            <input type="email" id="hessqfeFieldEmail" placeholder="jane@example.com" />
            <span class="hessqf-field-error" id="hessqfeErrEmail"></span>
          </div>
          <div class="hessqf-form-group">
            <label>Phone Number <span class="hessqf-required">*</span></label>
            <input type="tel" id="hessqfeFieldPhone" placeholder="(555) 867-5309" />
            <span class="hessqf-field-error" id="hessqfeErrPhone"></span>
          </div>
        </div>
        <div class="hessqf-form-group hessqf-full-width">
          <label>Address <span class="hessqf-required">*</span></label>
          <input type="text" id="hessqfeFieldAddress" placeholder="123 Main St, Brownsville, TX 78520" />
          <span class="hessqf-field-error" id="hessqfeErrAddress"></span>
        </div>
        <div class="hessqf-form-group">
          <label>When would you like to schedule?</label>
          <select id="hessqfeFieldSchedule">
            <option value="">— Select timing —</option>
            <option>Next 24 hours</option>
            <option>This week</option>
            <option>Next week</option>
            <option>This month</option>
          </select>
          <span class="hessqf-field-error" id="hessqfeErrSchedule"></span>
        </div>
        <div class="hessqf-form-group hessqf-full-width">
          <label>Other Requests or Comments</label>
          <textarea id="hessqfeFieldComments" placeholder="Any special instructions, access notes, or questions..."></textarea>
        </div>
        <div class="hessqf-form-group hessqf-full-width">
          <label>Signature <span class="hessqf-optional">(optional)</span></label>
          <div class="hessqf-signature-wrap">
            <canvas id="hessqfeSignaturePad" class="hessqf-signature-canvas" aria-label="Signature pad — sign with your mouse or finger"></canvas>
            <button type="button" id="hessqfeSignatureClear" class="hessqf-signature-clear">Clear</button>
          </div>
          <input type="hidden" id="hessqfeFieldSignature" />
        </div>
      </div>

      <div class="hessqf-step-nav" style="margin-top:24px;">
        <button type="button" class="hessqf-btn hessqf-btn-pink hessqf-btn-lg" id="hessqfeSubmitBtn">Submit Quote &rarr;</button>
      </div>
    </div>

  </div><!-- /stepPanel2 -->

  <!-- Confirmation Panel -->
  <div class="hessqf-confirmation-panel" id="hessqfeConfirmationPanel">
    <div class="hessqf-confirm-icon">&#10003;</div>
    <div class="hessqf-confirm-title">Quote Submitted!</div>
    <div class="hessqf-confirm-sub">Your quote request has been received. A team member will follow up shortly.</div>
    <div style="display:inline-block;background:#f4f5f7;border:1px solid #c6d8ee;border-radius:6px;padding:8px 20px;margin-bottom:24px;">
      <span style="font-size:0.78rem;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#666;">Quote Number&nbsp;&nbsp;</span>
      <span style="font-size:1.05rem;font-weight:700;color:#c0457a;letter-spacing:1px;" id="hessqfeConfirmQuoteNumber"></span>
    </div>
    <p class="hessqf-confirm-email-note" id="hessqfeConfirmEmailNote"></p>
    <div class="hessqf-confirm-details" id="hessqfeConfirmDetails"></div>
  </div>

  <!-- Inline JSON: data + config -->
  <script type="application/json" id="hessqfeSystemsData"><?php echo wp_json_encode( $config['systems'] ); ?></script>
  <script type="application/json" id="hessqfeConfigData"><?php echo wp_json_encode( [
      'tableCols'  => $config['tableCols'],
      'cardFields' => $config['cardFields'],
      'taxDefault' => $config['taxDefault'],
      'yearMode'   => $config['yearMode'],
  ] ); ?></script>

</div><!-- /.hessqf-form-wrap -->
