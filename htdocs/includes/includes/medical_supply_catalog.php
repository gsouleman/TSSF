<?php
declare(strict_types=1);

/**
 * Comprehensive medical / hospital supply names grouped by category.
 * Used to seed tbl_inventory_category + suggest product names per category on the inventory form.
 *
 * @return array<string, list<string>> category label => product names
 */
function hms_medical_supply_catalog(): array
{
    return [
        'Personal protective equipment (PPE)' => [
            'Nitrile examination gloves (box)',
            'Latex examination gloves (box)',
            'Sterile surgical gloves (pairs)',
            'Face mask — surgical 3-ply',
            'Face mask — FFP2 / N95',
            'Face shield — disposable',
            'Protective gown — isolation',
            'Protective gown — surgical sterile',
            'Shoe covers — disposable',
            'Hair cap / bouffant',
            'Safety goggles',
            'Apron — plastic disposable',
        ],
        'Syringes & needles' => [
            'Syringe 1 mL insulin U-100',
            'Syringe 2 mL Luer slip',
            'Syringe 5 mL Luer lock',
            'Syringe 10 mL Luer lock',
            'Syringe 20 mL Luer lock',
            'Hypodermic needle 18G',
            'Hypodermic needle 21G',
            'Hypodermic needle 23G',
            'Hypodermic needle 25G',
            'Butterfly needle (scalp vein set)',
            'Safety needle with shield',
            'Blunt fill / blunt filter needle',
        ],
        'Intravenous & infusion therapy' => [
            'IV cannula 18G',
            'IV cannula 20G',
            'IV cannula 22G',
            'IV cannula 24G',
            'Extension set — microbore',
            'Extension set — macrobore',
            'IV infusion set — vented',
            'IV infusion set — non-vented',
            'Three-way stopcock',
            'Needle-free injection cap',
            'Saline flush syringe 10 mL prefilled',
            'Pressure infusion bag',
        ],
        'Wound care & dressings' => [
            'Gauze roll — sterile',
            'Gauze pads 10×10 cm — sterile',
            'Non-adherent dressing',
            'Transparent film dressing',
            'Hydrocolloid dressing',
            'Alginate dressing',
            'Foam dressing',
            'Elastic bandage (crepe)',
            'Cohesive bandage (self-adherent)',
            'Adhesive tape — paper',
            'Adhesive tape — silk',
            'Antiseptic solution — povidone-iodine',
            'Antiseptic wipes — chlorhexidine',
        ],
        'Surgical consumables' => [
            'Surgical blade — sterile (assorted sizes)',
            'Scalpel handle — disposable',
            'Suction catheter — sterile',
            'Yankauer suction tip',
            'Lap sponge — sterile',
            'Abdominal pack — sterile',
            'Mayo stand cover — sterile',
            'Instrument tray liner',
            'Skin marker — surgical',
            'Electrosurgical grounding pad',
            'Bone wax',
            'Surgical lubricant gel — sterile',
        ],
        'Sutures & wound closure' => [
            'Absorbable suture 3-0',
            'Absorbable suture 4-0',
            'Non-absorbable suture 3-0',
            'Non-absorbable suture 4-0',
            'Surgical needle — cutting',
            'Surgical needle — taper point',
            'Skin stapler — disposable',
            'Staple remover',
            'Tissue adhesive — topical',
            'Steri-Strip skin closures',
            'Wound closure strips',
        ],
        'Anesthesia & airway management' => [
            'Endotracheal tube — cuffed (assorted)',
            'Laryngeal mask airway (LMA)',
            'Oropharyngeal airway (Guedel)',
            'Nasopharyngeal airway',
            'Breathing circuit — adult',
            'Breathing circuit — pediatric',
            'Heat-moisture exchanger (HME) filter',
            'Bite block',
            'Stylet — intubation',
            'Laryngoscope blade — disposable',
            'Suction catheter — tracheal',
            'Capnography line adapter',
        ],
        'Laboratory supplies & specimen collection' => [
            'Blood collection tube — EDTA (purple)',
            'Blood collection tube — serum (red/gold)',
            'Blood collection tube — citrate (blue)',
            'Blood collection tube — fluoride (grey)',
            'Vacutainer needle — multi-sample',
            'Lancet — safety',
            'Urine container — sterile',
            'Stool container',
            'Swab — viral transport medium',
            'Microscope slides',
            'Cover slips',
            'Pipette tips — sterile',
            'Specimen bag — biohazard',
        ],
        'Diagnostic consumables (POC)' => [
            'Glucose test strips',
            'Urine dipstick — 10-parameter',
            'Pregnancy test — hCG',
            'Hemoglobin test cassette',
            'Rapid malaria test (RDT)',
            'COVID-19 rapid antigen test',
            'Pulse oximeter probe — disposable',
            'ECG electrode — disposable',
            'Thermometer probe cover',
            'Blood pressure cuff — disposable',
        ],
        'Catheterization & urology' => [
            'Foley catheter — 2-way (assorted FR)',
            'Foley catheter — 3-way',
            'Urinary drainage bag — bedside',
            'Urinary leg bag',
            'Urine meter chamber',
            'Condom catheter — external',
            'Intermittent catheter — hydrophilic',
            'Urine specimen cup',
        ],
        'Obstetrics & gynecology' => [
            'Sterile delivery kit',
            'Umbilical cord clamp — sterile',
            'Bulb syringe — neonatal',
            'Perineal cold pack',
            'Maternity pad — sterile',
            'Speculum — disposable',
            'Cervical cytology brush',
            'Amnihook — sterile',
            'Fetal scalp electrode (if used)',
            'Breast pump collection set — sterile',
        ],
        'Sterilization wrap & packaging' => [
            'Sterilization pouch — self-seal',
            'CSR wrap — sterilization',
            'Biological indicator — steam',
            'Chemical indicator strips',
            'Sealing machine tape',
            'Instrument stringer / tags',
        ],
        'Disinfection & surface cleaning' => [
            'Surface disinfectant — quaternary ammonium',
            'Chlorine-based disinfectant tablets',
            'Alcohol prep pad — 70%',
            'Hand rub — alcohol-based (500 mL)',
            'Floor cleaner — hospital grade',
            'Spill kit — body fluid',
            'Microfiber mop head — disposable',
        ],
        'Radiology & imaging consumables' => [
            'Contrast syringe — CT',
            'Imaging table sheet — disposable',
            'Lead apron cover — disposable',
            'Ultrasound gel — sterile',
            'Ultrasound probe cover — sterile',
            'Marker clip — mammography',
            'MRI ear plugs — disposable',
        ],
        'Emergency & resuscitation' => [
            'Bag-valve-mask (BVM) — adult',
            'Bag-valve-mask (BVM) — pediatric',
            'Oxygen mask — simple',
            'Non-rebreather mask',
            'Nasal cannula — adult',
            'Oral airway kit',
            'Defibrillation pads — disposable',
            'Chest drainage kit — emergency',
            'Tourniquet — combat / emergency',
            'Spine board straps',
        ],
        'Pharmacy compounding & dispensing aids' => [
            'Pill counting spatula',
            'Mortar & pestle — glass',
            'Ointment slab',
            'Prescription vial — amber',
            'Child-resistant cap',
            'Medicine cup — graduated',
            'Oral syringe 1 mL',
            'Label roll — pharmacy',
        ],
        'Respiratory care consumables' => [
            'Nebulizer mask — adult',
            'Nebulizer mask — pediatric',
            'Spacer device — inhaler',
            'Oxygen humidifier bottle',
            'Tracheostomy dressing',
            'Suction catheter — closed system',
            'Peak flow meter mouthpiece — disposable',
        ],
        'Surgical instruments (reusable accessories)' => [
            'Instrument tray — perforated',
            'Sterilization container — rigid',
            'Instrument lubricant spray',
            'Cleaning brush — cannulated',
            'Instrument stringer / rack',
        ],
        'Linen & drapes' => [
            'Surgical drape — fenestrated',
            'Surgical drape — universal',
            'Mayo stand cover — cloth',
            'Patient gown — disposable',
            'Sheet — disposable',
            'Towel — sterile',
        ],
        'Nutrition & enteral feeding supplies' => [
            'Enteral feeding bag',
            'Enteral feeding set — pump',
            'NG tube — Fr (assorted)',
            'Syringe — enteral 60 mL',
            'pH indicator strips — gastric',
        ],
        'Sharps & waste containers' => [
            'Sharps container — 1 L',
            'Sharps container — 5 L',
            'Biohazard waste bag — red',
            'Pharmaceutical waste container',
            'Needle destroyer — portable',
        ],
        'Medical gases & accessories' => [
            'Oxygen cylinder wrench',
            'Flowmeter — oxygen',
            'Humidifier bottle — bubble',
            'Oxygen tubing — adult',
            'Cylinder cart',
        ],
    ];
}

/**
 * Product names for a category label (case-insensitive key match).
 *
 * @return list<string>
 */
function hms_medical_supply_products_for_category_name(string $categoryName): array
{
    $cat = hms_medical_supply_catalog();
    if (isset($cat[$categoryName])) {
        return $cat[$categoryName];
    }
    $t = trim($categoryName);
    foreach ($cat as $k => $products) {
        if (strcasecmp(trim($k), $t) === 0) {
            return $products;
        }
    }

    return [];
}
