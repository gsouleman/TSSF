<?php
require_once __DIR__ . '/includes/bootstrap.php';

$translations = [
    // Consultations
    'Consultation Nuit / Weekend' => 'Night / Weekend Consultation',
    'Consultation Urgence' => 'Emergency Consultation',
    'Consultation Avec Ordonnance' => 'Consultation With Prescription',
    'Consultation Généraliste' => 'General Consultation',
    'Consultation Cardiologue' => 'Cardiologist Consultation',
    'Consultation Dermatologue' => 'Dermatologist Consultation',
    'Consultation Gynécologue' => 'Gynecologist Consultation',
    'Consultation Ophtalmologue' => 'Ophthalmologist Consultation',
    'Consultation Orthopédiste' => 'Orthopedist Consultation',
    'Consultation Pédiatre' => 'Pediatrician Consultation',
    'Consultation Spécialiste' => 'Specialist Consultation',
    
    // Laboratory
    'Numération Formule Sanguine (NFS)' => 'Full Blood Count (FBC)',
    'Groupe Sanguin + Rhésus' => 'Blood Group + Rhesus',
    'Frottis Sanguin' => 'Blood Smear',
    'Taux de Plaquettes' => 'Platelet Count',
    'Glycémie (Glucose)' => 'Fasting Blood Sugar',
    'Urée Créatinine' => 'Urea & Creatinine',
    'Bilan Hepatique (ALAT/ASAT)' => 'Liver Function Test',
    'Cholestérol Total + HDL/LDL' => 'Lipid Profile',
    'Protéine C-Réactive (CRP)' => 'C-Reactive Protein (CRP)',
    'ECBU (Examen Cytobactério Urinaire)' => 'Urinalysis (ECBU)',
    'Hémoculture' => 'Blood Culture',
    'GE / TDR Paludisme' => 'Malaria Rapid Test / Thin Smear',
    'Test VIH (Dépistage)' => 'HIV Test',
    'AgHBs (Hépatite B)' => 'Hepatitis B Test',
    'TPHA/VDRL (Syphilis)' => 'Syphilis Test',
    'Bêta-HCG (Test de Grossesse)' => 'Pregnancy Test (Beta-HCG)',
    'TSH / T3 / T4 (Thyroïde)' => 'Thyroid Profile',
    'PSA (Antigène Prostatique)' => 'Prostate Specific Antigen (PSA)',
    'Radiographie Standard (1 vue)' => 'X-Ray (1 View)',
    'Échographie Abdominale' => 'Abdominal Ultrasound',
    'Échographie Obstétricale' => 'Obstetric Ultrasound',
    'Électrocardiogramme (ECG)' => 'Electrocardiogram (ECG)',
    
    // Other Services
    "Frais d'Admission / Dossier" => 'Admission / File Fee',
    'Soins Infirmiers (Injection/Pansement)' => 'Nursing Care (Injection/Dressing)',
    'Pose Perfusion (IV)' => 'IV Drip Placement',
    'Prise de Tension + Constantes' => 'Vitals / Blood Pressure Check',
    'Suture de Plaie (Simple)' => 'Wound Suturing (Simple)',
    'Suture de Plaie (Complexe)' => 'Wound Suturing (Complex)',
    'Extraction Corps Étranger' => 'Foreign Body Extraction',
    'Pose Sonde Urinaire' => 'Urinary Catheter Placement',
    'Circoncision' => 'Circumcision',
    'Hospitalisation (Salle Commune / Jour)' => 'Hospitalization (Ward / Day)',
    'Hospitalisation (Chambre Privée / Jour)' => 'Hospitalization (Private Room / Day)',
    'Soins Intensifs / Réanimation (Jour)' => 'Intensive Care (ICU / Day)',
    'Accouchement Normal (Eutocique)' => 'Normal Delivery',
    'Accouchement par Césarienne' => 'Cesarean Section',
    'Consultation Prénatale (CPN)' => 'Antenatal Consultation (ANC)',
    'Frais de Dispensation (Ordonnance)' => 'Dispensing Fee',
    'Certificat Médical' => 'Medical Certificate',
    'Carte de Santé / Visite Médicale' => 'Health Card / Medical Visit',
    'Transport Ambulance (Local)' => 'Ambulance Transport (Local)',
    'Transport Ambulance (Inter-Ville)' => 'Ambulance Transport (Inter-City)'
];

// Update translations
foreach ($translations as $fr => $en) {
    // Escaping to be safe
    $fr_sql = mysqli_real_escape_string($connection, $fr);
    $en_sql = mysqli_real_escape_string($connection, $en);
    mysqli_query($connection, "UPDATE tbl_service_catalog SET name = '$en_sql' WHERE name = '$fr_sql'");
}

// Add Pharmacy entries
$pharmacy = [
    ['pharmacy', 'Analgesics', 'Paracetamol 500mg', 'P001', 500],
    ['pharmacy', 'Analgesics', 'Ibuprofen 400mg', 'P002', 800],
    ['pharmacy', 'Analgesics', 'Diclofenac 50mg', 'P003', 1000],
    ['pharmacy', 'Analgesics', 'Efferalgan 500mg', 'P004', 1500],
    ['pharmacy', 'Antimalarials', 'Artemether/Lumefantrine 20/120mg', 'P010', 2500],
    ['pharmacy', 'Antimalarials', 'Artesunate Injection 60mg', 'P011', 3000],
    ['pharmacy', 'Antimalarials', 'Quinine Sulfate 300mg', 'P012', 1500],
    ['pharmacy', 'Antibiotics', 'Amoxicillin 500mg', 'P020', 1000],
    ['pharmacy', 'Antibiotics', 'Azithromycin 500mg', 'P021', 2500],
    ['pharmacy', 'Antibiotics', 'Ciprofloxacin 500mg', 'P022', 1800],
    ['pharmacy', 'Antibiotics', 'Metronidazole 500mg', 'P023', 800],
    ['pharmacy', 'Antibiotics', 'Ceftriaxone 1g IV', 'P024', 3000],
    ['pharmacy', 'Gastrointestinal', 'Omeprazole 20mg', 'P030', 2000],
    ['pharmacy', 'Gastrointestinal', 'Spasfon (Phloroglucinol 80mg)', 'P031', 2000],
    ['pharmacy', 'Supplements', 'Vitamin C 1000mg', 'P040', 1000],
    ['pharmacy', 'Supplements', 'Oral Rehydration Salts (ORS)', 'P041', 500]
];

foreach ($pharmacy as $drug) {
    $cat = $drug[0];
    $sub = $drug[1];
    $name = mysqli_real_escape_string($connection, $drug[2]);
    $cpt = $drug[3];
    $price = $drug[4];
    
    mysqli_query($connection, "INSERT IGNORE INTO tbl_service_catalog (facility_id, category, subcategory, name, cpt_code, price) VALUES (0, '$cat', '$sub', '$name', '$cpt', $price)");
}

echo "Database successfully translated and pharmacy populated!";
