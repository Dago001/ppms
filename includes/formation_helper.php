<?php
// includes/formation_helper.php
// Global NIS Formation Resolver - Maps any location mention to proper formation name

/**
 * Nigeria's 36 states + FCT, each mapped to its full list of Local Government
 * Areas / Area Councils. Sourced from Wikipedia and spot-verified against
 * individual state pages; this powers an OPTIONAL refinement dropdown on the
 * posting location, not an enforcement/validation list, so a rare missing or
 * misspelled LGA is a minor UX gap, not a data-integrity risk.
 */
function getNigeriaLGAsByState() {
    return [
        'Abia' => ['Aba North', 'Aba South', 'Arochukwu', 'Bende', 'Ikwuano', 'Isiala Ngwa North', 'Isiala Ngwa South', 'Isuikwuato', 'Obi Ngwa', 'Ohafia', 'Osisioma Ngwa', 'Ugwunagbo', 'Ukwa East', 'Ukwa West', 'Umuahia North', 'Umuahia South', 'Umu Nneochi'],
        'Adamawa' => ['Demsa', 'Fufure', 'Ganye', 'Guyuk', 'Girei', 'Gombi', 'Hong', 'Jada', 'Lamurde', 'Madagali', 'Maiha', 'Mayo Belwa', 'Michika', 'Mubi North', 'Mubi South', 'Numan', 'Shelleng', 'Song', 'Toungo', 'Yola North', 'Yola South'],
        'Akwa Ibom' => ['Abak', 'Eastern Obolo', 'Eket', 'Esit Eket', 'Essien Udim', 'Etim Ekpo', 'Etinan', 'Ibeno', 'Ibesikpo Asutan', 'Ibiono-Ibom', 'Ika', 'Ikono', 'Ikot Abasi', 'Ikot Ekpene', 'Ini', 'Itu', 'Mbo', 'Mkpat-Enin', 'Nsit-Atai', 'Nsit-Ibom', 'Nsit-Ubium', 'Obot Akara', 'Okobo', 'Onna', 'Oron', 'Oruk Anam', 'Udung-Uko', 'Ukanafun', 'Uruan', 'Urue-Offong/Oruko', 'Uyo'],
        'Anambra' => ['Aguata', 'Anambra East', 'Anambra West', 'Anaocha', 'Awka North', 'Awka South', 'Ayamelum', 'Dunukofia', 'Ekwusigo', 'Idemili North', 'Idemili South', 'Ihiala', 'Njikoka', 'Nnewi North', 'Nnewi South', 'Ogbaru', 'Onitsha North', 'Onitsha South', 'Orumba North', 'Orumba South', 'Oyi'],
        'Bauchi' => ['Alkaleri', 'Bauchi', 'Bogoro', 'Damban', 'Darazo', 'Dass', 'Gamawa', 'Ganjuwa', 'Giade', "Itas/Gadau", "Jama'are", 'Katagum', 'Kirfi', 'Misau', 'Ningi', 'Shira', 'Tafawa Balewa', 'Toro', 'Warji', 'Zaki'],
        'Bayelsa' => ['Brass', 'Ekeremor', 'Kolokuma/Opokuma', 'Nembe', 'Ogbia', 'Sagbama', 'Southern Ijaw', 'Yenagoa'],
        'Benue' => ['Ado', 'Agatu', 'Apa', 'Buruku', 'Gboko', 'Guma', 'Gwer East', 'Gwer West', 'Katsina-Ala', 'Konshisha', 'Kwande', 'Logo', 'Makurdi', 'Obi', 'Ogbadibo', 'Ohimini', 'Oju', 'Okpokwu', 'Oturkpo', 'Tarka', 'Ukum', 'Ushongo', 'Vandeikya'],
        'Borno' => ['Abadam', 'Askira/Uba', 'Bama', 'Bayo', 'Biu', 'Chibok', 'Damboa', 'Dikwa', 'Gubio', 'Guzamala', 'Gwoza', 'Hawul', 'Jere', 'Kaga', 'Kala/Balge', 'Konduga', 'Kukawa', 'Kwaya Kusar', 'Mafa', 'Magumeri', 'Maiduguri', 'Marte', 'Mobbar', 'Monguno', 'Ngala', 'Nganzai', 'Shani'],
        'Cross River' => ['Abi', 'Akamkpa', 'Akpabuyo', 'Bakassi', 'Bekwarra', 'Biase', 'Boki', 'Calabar Municipal', 'Calabar South', 'Etung', 'Ikom', 'Obanliku', 'Obubra', 'Obudu', 'Odukpani', 'Ogoja', 'Yakurr', 'Yala'],
        'Delta' => ['Aniocha North', 'Aniocha South', 'Bomadi', 'Burutu', 'Ethiope East', 'Ethiope West', 'Ika North East', 'Ika South', 'Isoko North', 'Isoko South', 'Ndokwa East', 'Ndokwa West', 'Okpe', 'Oshimili North', 'Oshimili South', 'Patani', 'Sapele', 'Udu', 'Ughelli North', 'Ughelli South', 'Ukwuani', 'Uvwie', 'Warri North', 'Warri South', 'Warri South West'],
        'Ebonyi' => ['Abakaliki', 'Afikpo North', 'Afikpo South', 'Ebonyi', 'Ezza North', 'Ezza South', 'Ikwo', 'Ishielu', 'Ivo', 'Izzi', 'Ohaozara', 'Ohaukwu', 'Onicha'],
        'Edo' => ['Akoko-Edo', 'Egor', 'Esan Central', 'Esan North-East', 'Esan South-East', 'Esan West', 'Etsako Central', 'Etsako East', 'Etsako West', 'Igueben', 'Ikpoba Okha', 'Orhionmwon', 'Oredo', 'Ovia North-East', 'Ovia South-West', 'Owan East', 'Owan West', 'Uhunmwonde'],
        'Ekiti' => ['Ado Ekiti', 'Efon', 'Ekiti East', 'Ekiti South-West', 'Ekiti West', 'Emure', 'Gbonyin', 'Ido Osi', 'Ijero', 'Ikere', 'Ikole', 'Ilejemeje', 'Irepodun/Ifelodun', 'Ise/Orun', 'Moba', 'Oye'],
        'Enugu' => ['Aninri', 'Awgu', 'Enugu East', 'Enugu North', 'Enugu South', 'Ezeagu', 'Igbo Etiti', 'Igbo Eze North', 'Igbo Eze South', 'Isi Uzo', 'Nkanu East', 'Nkanu West', 'Nsukka', 'Oji River', 'Udenu', 'Udi', 'Uzo-Uwani'],
        'Gombe' => ['Akko', 'Balanga', 'Billiri', 'Dukku', 'Funakaye', 'Gombe', 'Kaltungo', 'Kwami', 'Nafada', 'Shongom', 'Yamaltu/Deba'],
        'Imo' => ['Aboh Mbaise', 'Ahiazu Mbaise', 'Ehime Mbano', 'Ezinihitte Mbaise', 'Ideato North', 'Ideato South', 'Ihitte/Uboma', 'Ikeduru', 'Isiala Mbano', 'Isu', 'Mbaitoli', 'Ngor Okpala', 'Njaba', 'Nkwerre', 'Nwangele', 'Obowo', 'Oguta', 'Ohaji/Egbema', 'Okigwe', 'Onuimo', 'Orlu', 'Orsu', 'Oru East', 'Oru West', 'Owerri Municipal', 'Owerri North', 'Owerri West'],
        'Jigawa' => ['Auyo', 'Babura', 'Biriniwa', 'Birnin Kudu', 'Buji', 'Dutse', 'Gagarawa', 'Garki', 'Gumel', 'Guri', 'Gwaram', 'Gwiwa', 'Hadejia', 'Jahun', 'Kafin Hausa', 'Kaugama', 'Kazaure', 'Kiri Kasama', 'Kiyawa', 'Maigatari', 'Malam Madori', 'Miga', 'Ringim', 'Roni', 'Sule Tankarkar', 'Taura', 'Yankwashi'],
        'Kaduna' => ['Birnin Gwari', 'Chikun', 'Giwa', 'Igabi', 'Ikara', 'Jaba', "Jema'a", 'Kachia', 'Kaduna North', 'Kaduna South', 'Kagarko', 'Kajuru', 'Kaura', 'Kauru', 'Kubau', 'Kudan', 'Lere', 'Makarfi', 'Sabon Gari', 'Sanga', 'Soba', 'Zangon Kataf', 'Zaria'],
        'Kano' => ['Ajingi', 'Albasu', 'Bagwai', 'Bebeji', 'Bichi', 'Bunkure', 'Dala', 'Dambatta', 'Dawakin Kudu', 'Dawakin Tofa', 'Doguwa', 'Fagge', 'Gabasawa', 'Garko', 'Garun Mallam', 'Gaya', 'Gezawa', 'Gwale', 'Gwarzo', 'Kabo', 'Kano Municipal', 'Karaye', 'Kibiya', 'Kiru', 'Kumbotso', 'Kunchi', 'Kura', 'Madobi', 'Makoda', 'Minjibir', 'Nasarawa', 'Rano', 'Rimin Gado', 'Rogo', 'Shanono', 'Sumaila', 'Takai', 'Tarauni', 'Tofa', 'Tsanyawa', 'Tudun Wada', 'Ungogo', 'Warawa', 'Wudil'],
        'Katsina' => ['Bakori', 'Batagarawa', 'Batsari', 'Baure', 'Bindawa', 'Charanchi', 'Dandume', 'Danja', 'Dan Musa', 'Daura', 'Dutsi', 'Dutsin-Ma', 'Faskari', 'Funtua', 'Ingawa', 'Jibia', 'Kafur', 'Kaita', 'Kankara', 'Kankia', 'Katsina', 'Kurfi', 'Kusada', "Mai'Adua", 'Malumfashi', 'Mani', 'Mashi', 'Matazu', 'Musawa', 'Rimi', 'Sabuwa', 'Safana', 'Sandamu', 'Zango'],
        'Kebbi' => ['Aleiro', 'Arewa-Dandi', 'Argungu', 'Augie', 'Bagudo', 'Birnin Kebbi', 'Bunza', 'Dandi', 'Fakai', 'Gwandu', 'Jega', 'Kalgo', 'Koko/Besse', 'Maiyama', 'Ngaski', 'Sakaba', 'Shanga', 'Suru', 'Wasagu/Danko', 'Yauri', 'Zuru'],
        'Kogi' => ['Adavi', 'Ajaokuta', 'Ankpa', 'Bassa', 'Dekina', 'Ibaji', 'Idah', 'Igalamela-Odolu', 'Ijumu', 'Kabba/Bunu', 'Kogi', 'Lokoja', 'Mopa-Muro', 'Ofu', 'Ogori/Magongo', 'Okehi', 'Okene', 'Olamaboro', 'Omala', 'Yagba East', 'Yagba West'],
        'Kwara' => ['Asa', 'Baruten', 'Edu', 'Ekiti', 'Ifelodun', 'Ilorin East', 'Ilorin South', 'Ilorin West', 'Irepodun', 'Isin', 'Kaiama', 'Moro', 'Offa', 'Oke Ero', 'Oyun', 'Pategi'],
        'Lagos' => ['Agege', 'Ajeromi-Ifelodun', 'Alimosho', 'Amuwo-Odofin', 'Apapa', 'Badagry', 'Epe', 'Eti Osa', 'Ibeju-Lekki', 'Ifako-Ijaiye', 'Ikeja', 'Ikorodu', 'Kosofe', 'Lagos Island', 'Lagos Mainland', 'Mushin', 'Ojo', 'Oshodi-Isolo', 'Shomolu', 'Surulere'],
        'Nasarawa' => ['Akwanga', 'Awe', 'Doma', 'Karu', 'Keana', 'Keffi', 'Kokona', 'Lafia', 'Nasarawa', 'Nasarawa Eggon', 'Obi', 'Toto', 'Wamba'],
        'Niger' => ['Agaie', 'Agwara', 'Bida', 'Borgu', 'Bosso', 'Chanchaga', 'Edati', 'Gbako', 'Gurara', 'Katcha', 'Kontagora', 'Lapai', 'Lavun', 'Magama', 'Mariga', 'Mashegu', 'Mokwa', 'Munya', 'Paikoro', 'Rafi', 'Rijau', 'Shiroro', 'Suleja', 'Tafa', 'Wushishi'],
        'Ogun' => ['Abeokuta North', 'Abeokuta South', 'Ado-Odo/Ota', 'Ewekoro', 'Ifo', 'Ijebu East', 'Ijebu North', 'Ijebu North East', 'Ijebu Ode', 'Ikenne', 'Imeko Afon', 'Ipokia', 'Obafemi Owode', 'Odeda', 'Odogbolu', 'Ogun Waterside', 'Remo North', 'Shagamu', 'Yewa North', 'Yewa South'],
        'Ondo' => ['Akoko North-East', 'Akoko North-West', 'Akoko South-East', 'Akoko South-West', 'Akure North', 'Akure South', 'Ese Odo', 'Idanre', 'Ifedore', 'Ilaje', 'Ile Oluji/Okeigbo', 'Irele', 'Odigbo', 'Okitipupa', 'Ondo East', 'Ondo West', 'Ose', 'Owo'],
        'Osun' => ['Aiyedaade', 'Aiyedire', 'Atakunmosa East', 'Atakunmosa West', 'Boluwaduro', 'Boripe', 'Ede North', 'Ede South', 'Egbedore', 'Ejigbo', 'Ife Central', 'Ife East', 'Ife North', 'Ife South', 'Ifedayo', 'Ifelodun', 'Ila', 'Ilesa East', 'Ilesa West', 'Irepodun', 'Irewole', 'Isokan', 'Iwo', 'Obokun', 'Odo Otin', 'Ola Oluwa', 'Olorunda', 'Oriade', 'Orolu', 'Osogbo'],
        'Oyo' => ['Afijio', 'Akinyele', 'Atiba', 'Atisbo', 'Egbeda', 'Ibadan North', 'Ibadan North-East', 'Ibadan North-West', 'Ibadan South-East', 'Ibadan South-West', 'Ibarapa Central', 'Ibarapa East', 'Ibarapa North', 'Ido', 'Irepo', 'Iseyin', 'Itesiwaju', 'Iwajowa', 'Kajola', 'Lagelu', 'Ogbomosho North', 'Ogbomosho South', 'Ogo Oluwa', 'Olorunsogo', 'Oluyole', 'Ona Ara', 'Orelope', 'Ori Ire', 'Oyo East', 'Oyo West', 'Saki East', 'Saki West', 'Surulere'],
        'Plateau' => ['Barkin Ladi', 'Bassa', 'Bokkos', 'Jos East', 'Jos North', 'Jos South', 'Kanam', 'Kanke', 'Langtang North', 'Langtang South', 'Mangu', 'Mikang', 'Pankshin', "Qua'an Pan", 'Riyom', 'Shendam', 'Wase'],
        'Rivers' => ['Abua-Odual', 'Ahoada East', 'Ahoada West', 'Akuku-Toru', 'Andoni', 'Asari-Toru', 'Bonny', 'Degema', 'Eleme', 'Emohua', 'Etche', 'Gokana', 'Ikwerre', 'Khana', 'Obio-Akpor', 'Ogba-Egbema-Ndoni', 'Ogu-Bolo', 'Okrika', 'Omuma', 'Opobo-Nkoro', 'Oyigbo', 'Port Harcourt', 'Tai'],
        'Sokoto' => ['Binji', 'Bodinga', 'Dange Shuni', 'Gada', 'Goronyo', 'Gudu', 'Gwadabawa', 'Illela', 'Isa', 'Kebbe', 'Kware', 'Rabah', 'Sabon Birni', 'Shagari', 'Silame', 'Sokoto North', 'Sokoto South', 'Tambuwal', 'Tangaza', 'Tureta', 'Wamako', 'Wurno', 'Yabo'],
        'Taraba' => ['Ardo Kola', 'Bali', 'Donga', 'Gashaka', 'Gassol', 'Ibi', 'Jalingo', 'Karim Lamido', 'Kurmi', 'Lau', 'Sardauna', 'Takum', 'Ussa', 'Wukari', 'Yorro', 'Zing'],
        'Yobe' => ['Bade', 'Bursari', 'Damaturu', 'Fika', 'Fune', 'Geidam', 'Gujba', 'Gulani', 'Jakusko', 'Karasuwa', 'Machina', 'Nangere', 'Nguru', 'Potiskum', 'Tarmuwa', 'Yunusari', 'Yusufari'],
        'Zamfara' => ['Anka', 'Bakura', 'Birnin Magaji/Kiyaw', 'Bukkuyum', 'Bungudu', 'Gummi', 'Gusau', 'Kaura Namoda', 'Maradun', 'Maru', 'Shinkafi', 'Talata Mafara', 'Tsafe', 'Zurmi'],
        'FCT' => ['Abaji', 'Abuja Municipal Area Council', 'Bwari', 'Gwagwalada', 'Kuje', 'Kwali'],
    ];
}

/**
 * Resolve a State Command formation name (e.g. "Abia State Command") to its
 * plain state name (e.g. "Abia") for LGA lookup. Returns '' if the name
 * doesn't look like a state-level command (e.g. a Zone HQ, Directorate, or
 * Border Command has no state-wide LGA breakdown).
 */
function resolveStateNameFromCommand($commandName) {
    $name = trim($commandName);
    if ($name === 'FCT Command') return 'FCT';
    if (preg_match('/^(.+?)\s+State Command$/i', $name, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Get the list of LGAs under a given formation/command name, or an empty
 * array if that formation isn't a state-level command (e.g. HQ, Border Post,
 * Directorate, Airport Command - these have no LGA breakdown).
 */
function getLGAsForCommand($commandName) {
    $stateName = resolveStateNameFromCommand($commandName);
    if ($stateName === '') return [];
    $allStates = getNigeriaLGAsByState();
    return $allStates[$stateName] ?? [];
}

function getNISFormations() {
    return [
        'SHQ - Service Headquarters' => [
            'NIS HQ Abuja' => 'NIS Headquarters, Abuja',
            'CGIS OFFICE' => 'CGIS Office',
            'VRD' => 'Visa and Residency Directorate',
            'POTD' => 'Passport and OTD Directorate',
            'FAD' => 'Finance and Account Directorate',
            'PRSD' => 'PRS Directorate',
            'ICD' => 'I & C Directorate',
            'MD' => 'Migration Directorate',
            'BMD' => 'Border Management Directorate',
            'HRMD' => 'Human Resource Management',
            'WLD' => 'Works and Logistics Directorate',
            'ICTD' => 'ICT and Cyber Security Directorate'
        ],
        'ZONE A' => [
            'Zone A HQ Ikeja' => 'Zone A HQ Ikeja',
            'LASC' => 'Lagos State Command',
            'OGSC' => 'Ogun State Command',
            'LASPC' => 'Lagos Seaport and Marine Command',
            'SEME' => 'Seme Border Command',
            'IDBC' => 'Idiroko Border Command',
            'MMIA' => 'MMIA',
            'LABPC' => 'Lagos Border Patrol Command',
            'LAPC' => 'Lagos Passport Command'
        ],
        'ZONE B' => [
            'Zone B HQ Kaduna' => 'Zone B HQ Kaduna',
            'KNSC' => 'Kano State Command',
            'KDSC' => 'Kaduna State Command',
            'KTSC' => 'Katsina State Command',
            'ZMSC' => 'Zamfara State Command',
            'SOSC' => 'Sokoto State Command',
            'JGSC' => 'Jigawa State Command',
            'MAKIA' => 'MAKIA',
            'ILBC' => 'Illela Border Command',
            'JIBC' => 'Jibia Border Command',
            'ITSK' => 'Immigration Training School Kano',
            'ICSC' => 'Immigration Command and Staff College'
        ],
        'ZONE C' => [
            'Zone C HQ Bauchi' => 'Zone C HQ Bauchi',
            'ADSC' => 'Adamawa State Command',
            'BASC' => 'Bauchi State Command',
            'BOSC' => 'Borno State Command',
            'GMSC' => 'Gombe State Command',
            'PLSC' => 'Plateau State Command',
            'YBSC' => 'Yobe State Command'
        ],
        'ZONE D' => [
            'Zone D HQ Minna' => 'Zone D HQ Minna',
            'FCTC' => 'FCT Command',
            'NGSC' => 'Niger State Command',
            'KBSC' => 'Kebbi State Command',
            'RMAT' => 'Regional Migration Academy, Tuga',
            'KWSC' => 'Kwara State Command'
        ],
        'ZONE E' => [
            'Zone E HQ Owerri' => 'Zone E HQ Owerri',
            'Abia State Command' => 'Abia State Command',
            'IMSC' => 'Imo State Command',
            'RVSC' => 'Rivers State Command',
            'CRSC' => 'Cross River State Command',
            'EBSC' => 'Ebonyi State Command',
            'AKSC' => 'Akwa Ibom State Command',
            'NITSOL' => 'NITS Orlu',
            'RVMC' => 'Rivers Marine Command Onne',
            'MFBC' => 'Mfum Border Command',
            'NITSA' => 'NITS Ahoada'
        ],
        'ZONE F' => [
            'Zone F HQ Ibadan' => 'Zone F HQ Ibadan',
            'OYSC' => 'Oyo State Command',
            'EKSC' => 'Ekiti State Command',
            'ODSC' => 'Ondo State Command',
            'OSSC' => 'Osun State Command'
        ],
        'ZONE G' => [
            'Zone G HQ Benin' => 'Zone G HQ Benin',
            'EDSC' => 'Edo State Command',
            'ANSC' => 'Anambra State Command',
            'DTSC' => 'Delta State Command',
            'ENSC' => 'Enugu State Command',
            'BYSC' => 'Bayelsa State Command'
        ],
        'ZONE H' => [
            'Zone H HQ Makurdi' => 'Zone H HQ Makurdi',
            'NASC' => 'Nasarawa State Command',
            'BNSC' => 'Benue State Command',
            'KGSC' => 'Kogi State Command',
            'TRSC' => 'Taraba State Command'
        ]
    ];
}

/**
 * Build a comprehensive lookup table for formation resolution
 * Returns array with multiple keys pointing to the same formation
 */
function buildFormationLookup() {
    static $lookup = null;
    if ($lookup !== null) return $lookup;
    
    $formations = getNISFormations();
    $lookup = [];
    
    foreach ($formations as $zone => $commands) {
        // Add zone name
        $lookup[strtoupper(trim($zone))] = [
            'full_name' => $zone,
            'code' => $zone,
            'zone' => $zone
        ];
        
        foreach ($commands as $code => $fullName) {
            $key = strtoupper(trim($fullName));
            $codeKey = strtoupper(trim($code));
            
            $entry = [
                'full_name' => $fullName,
                'code' => $code,
                'zone' => $zone
            ];
            
            // Map full name
            $lookup[$key] = $entry;
            
            // Map code
            $lookup[$codeKey] = $entry;
            
            // Map common abbreviations/partials
            $words = explode(' ', $key);
            if (count($words) >= 2) {
                $mainWord = $words[0];
                if (!isset($lookup[$mainWord])) {
                    $lookup[$mainWord] = $entry;
                } elseif (is_array($lookup[$mainWord]) && $lookup[$mainWord]['full_name'] !== $fullName) {
                    $lookup[$mainWord] = null;
                }
            }
        }
    }
    
    return $lookup;
}

/**
 * Resolve a location string to its proper NIS formation name
 * @param string $location - Raw location from database
 * @return array - ['full_name' => '...', 'code' => '...', 'zone' => '...']
 */
function resolveFormation($location) {
    if (empty($location)) {
        return ['full_name' => 'N/A', 'code' => 'N/A', 'zone' => 'N/A'];
    }
    
    $lookup = buildFormationLookup();
    $searchKey = strtoupper(trim($location));
    
    if (isset($lookup[$searchKey]) && is_array($lookup[$searchKey])) {
        return $lookup[$searchKey];
    }
    
    $cleanKey = str_replace([' COMMAND', ' STATE', ' BORDER', ' OFFICE', ' DIRECTORATE', ' HQ'], '', $searchKey);
    if ($cleanKey !== $searchKey && isset($lookup[$cleanKey]) && is_array($lookup[$cleanKey])) {
        return $lookup[$cleanKey];
    }
    
    foreach ($lookup as $key => $entry) {
        if (!is_array($entry)) continue;
        if (strlen($key) < 3) continue;
        
        if (stripos($searchKey, $key) !== false || stripos($key, $searchKey) !== false) {
            return $entry;
        }
    }
    
    $searchWords = explode(' ', $searchKey);
    foreach ($searchWords as $word) {
        if (strlen($word) < 3) continue;
        if (isset($lookup[$word]) && is_array($lookup[$word])) {
            return $lookup[$word];
        }
    }
    
    return [
        'full_name' => $location,
        'code' => $location,
        'zone' => 'Unknown'
    ];
}

/**
 * Get just the formation name from a location
 */
function getFormationName($location) {
    $result = resolveFormation($location);
    return $result['full_name'];
}

/**
 * Get the formation code from a location
 */
function getFormationCode($location) {
    $result = resolveFormation($location);
    return $result['code'];
}

/**
 * Get the zone from a location
 */
function getFormationZone($location) {
    $result = resolveFormation($location);
    return $result['zone'];
}

/**
 * Get all possible match strings for a formation (for SQL filtering)
 * Returns array of strings to search for
 */
function getFormationSearchTerms($formationName) {
    $result = resolveFormation($formationName);
    $terms = [$result['full_name']];
    
    if ($result['code'] !== $result['full_name']) {
        $terms[] = $result['code'];
    }
    
    $words = explode(' ', strtoupper($result['full_name']));
    if (count($words) >= 2) {
        $terms[] = $words[0];
    }
    
    return array_unique($terms);
}

// ============================================
// NEW: USER ZONE FILTER BUILDER
// ============================================

/**
 * Build SQL filter for user's zone/command assignments
 * Used across dashboard, search, notifications, analytics, reports
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID to get assignments for
 * @param string $tableAlias Table alias for the column (e.g., 'e', 'te')
 * @param string $columnName Column name for posting location (e.g., 'presentPosting', 'posting_location')
 * @return array ['sql' => SQL string, 'params' => array of params, 'zone_names' => array of display names]
 */
function buildUserZoneFilter($pdo, $userId, $tableAlias = 'e', $columnName = 'presentPosting') {
    $filterSQL = "";
    $filterParams = [];
    $userZoneNames = [];
    
    try {
        // Check if assigned_command column exists
        $hasAssignedCommand = false;
        $columns = $pdo->query("SHOW COLUMNS FROM user_zones LIKE 'assigned_command'")->fetchAll();
        if (count($columns) > 0) {
            $hasAssignedCommand = true;
        }
        
        if ($hasAssignedCommand) {
            // Get both zone and specific command assignments
            $stmt = $pdo->prepare("SELECT uz.assigned_command, z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
            $stmt->execute([$userId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $conditions = [];
            foreach ($assignments as $assignment) {
                if (!empty($assignment['assigned_command'])) {
                    // Specific command - filter exactly by command name
                    $cmdName = $assignment['assigned_command'];
                    $conditions[] = "{$tableAlias}.{$columnName} LIKE ?";
                    $filterParams[] = "%" . $cmdName . "%";
                    $userZoneNames[] = $cmdName;
                } elseif (!empty($assignment['zone_name'])) {
                    // All commands in zone - filter by zone name
                    $conditions[] = "{$tableAlias}.{$columnName} LIKE ?";
                    $filterParams[] = "%" . $assignment['zone_name'] . "%";
                    $userZoneNames[] = $assignment['zone_name'] . ' (All Commands)';
                }
            }
            
            if (!empty($conditions)) {
                $filterSQL = " AND (" . implode(" OR ", $conditions) . ")";
            }
        } else {
            // Fallback: Original zone-only filtering
            $stmt = $pdo->prepare("SELECT z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
            $stmt->execute([$userId]);
            $zoneNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($zoneNames)) {
                $conditions = [];
                foreach ($zoneNames as $zn) {
                    $conditions[] = "{$tableAlias}.{$columnName} LIKE ?";
                    $filterParams[] = "%" . $zn . "%";
                }
                $filterSQL = " AND (" . implode(" OR ", $conditions) . ")";
                $userZoneNames = $zoneNames;
            }
        }
    } catch (Exception $e) {
        // Silent fail - return empty filter
    }
    
    return [
        'sql' => $filterSQL,
        'params' => $filterParams,
        'zone_names' => $userZoneNames
    ];
}

/**
 * Build SQL filter for posting_notifications table specifically
 * Filters by posting_location and posting_zone columns
 */
function buildPostingNotificationFilter($pdo, $userId) {
    $filterSQL = "";
    $filterParams = [];
    
    try {
        $hasAssignedCommand = false;
        $columns = $pdo->query("SHOW COLUMNS FROM user_zones LIKE 'assigned_command'")->fetchAll();
        if (count($columns) > 0) {
            $hasAssignedCommand = true;
        }
        
        if ($hasAssignedCommand) {
            $stmt = $pdo->prepare("SELECT uz.assigned_command, z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
            $stmt->execute([$userId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $conditions = [];
            foreach ($assignments as $assignment) {
                if (!empty($assignment['assigned_command'])) {
                    $cmdName = $assignment['assigned_command'];
                    $conditions[] = "(posting_location LIKE ? OR posting_zone LIKE ?)";
                    $filterParams[] = "%" . $cmdName . "%";
                    $filterParams[] = "%" . $cmdName . "%";
                } elseif (!empty($assignment['zone_name'])) {
                    $conditions[] = "(posting_location LIKE ? OR posting_zone LIKE ?)";
                    $filterParams[] = "%" . $assignment['zone_name'] . "%";
                    $filterParams[] = "%" . $assignment['zone_name'] . "%";
                }
            }
            
            if (!empty($conditions)) {
                $filterSQL = " AND (" . implode(" OR ", $conditions) . ")";
            }
        } else {
            $stmt = $pdo->prepare("SELECT z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
            $stmt->execute([$userId]);
            $zoneNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($zoneNames)) {
                $conditions = [];
                foreach ($zoneNames as $zn) {
                    $conditions[] = "(posting_location LIKE ? OR posting_zone LIKE ?)";
                    $filterParams[] = "%" . $zn . "%";
                    $filterParams[] = "%" . $zn . "%";
                }
                $filterSQL = " AND (" . implode(" OR ", $conditions) . ")";
            }
        }
    } catch (Exception $e) {}
    
    return [
        'sql' => $filterSQL,
        'params' => $filterParams
    ];
}

/**
 * Get all command names for a given zone from the NIS formations array
 */
function getZoneCommandsFromFormations($nis_formations, $zoneName) {
    $searchZone = strtoupper(trim($zoneName));
    
    foreach ($nis_formations as $zoneKey => $commands) {
        $keyUpper = strtoupper(trim($zoneKey));
        
        // Direct match
        if ($searchZone === $keyUpper) {
            return array_values($commands);
        }
        
        // Contains match
        if (stripos($keyUpper, $searchZone) !== false || stripos($searchZone, $keyUpper) !== false) {
            return array_values($commands);
        }
        
        // Match by zone letter (e.g., "ZONE D" matches "ZONE D")
        if (preg_match('/ZONE\s+([A-H])/', $searchZone, $m1) && 
            preg_match('/ZONE\s+([A-H])/', $keyUpper, $m2) && 
            $m1[1] === $m2[1]) {
            return array_values($commands);
        }
    }
    
    return [$zoneName];
}

/**
 * Build SQL filter for user's zone/command assignments
 * Usage: $filter = buildUserFilter($pdo, $userId, $nis_formations, 'e', 'presentPosting');
 */
function buildUserFilter($pdo, $userId, $nis_formations, $tableAlias = 'e', $columnName = 'presentPosting') {
    $filterSQL = "";
    $filterParams = [];
    $userZoneNames = [];
    $searchTerms = [];
    
    try {
        $hasAssignedCommand = false;
        $columns = $pdo->query("SHOW COLUMNS FROM user_zones LIKE 'assigned_command'")->fetchAll();
        if (count($columns) > 0) $hasAssignedCommand = true;
        
        if ($hasAssignedCommand) {
            $stmt = $pdo->prepare("SELECT uz.assigned_command, z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
            $stmt->execute([$userId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $conditions = [];
            foreach ($assignments as $assignment) {
                if (!empty($assignment['assigned_command'])) {
                    // SPECIFIC COMMAND - only this command
                    $cmdName = $assignment['assigned_command'];
                    $conditions[] = "{$tableAlias}.{$columnName} LIKE ?";
                    $filterParams[] = "%" . $cmdName . "%";
                    $userZoneNames[] = $assignment['zone_name'] . ' (' . $cmdName . ')';
                    $searchTerms[] = $cmdName;
                } elseif (!empty($assignment['zone_name'])) {
                    // ALL COMMANDS IN ZONE - expand to all commands
                    $zoneName = $assignment['zone_name'];
                    $zoneCommands = getZoneCommandsFromFormations($nis_formations, $zoneName);
                    
                    $zoneConds = [];
                    foreach ($zoneCommands as $cmd) {
                        $zoneConds[] = "{$tableAlias}.{$columnName} LIKE ?";
                        $filterParams[] = "%" . $cmd . "%";
                        $searchTerms[] = $cmd;
                    }
                    if (!empty($zoneConds)) {
                        $conditions[] = "(" . implode(" OR ", $zoneConds) . ")";
                    }
                    $userZoneNames[] = $zoneName . ' (All Commands)';
                }
            }
            
            if (!empty($conditions)) {
                $filterSQL = " AND (" . implode(" OR ", $conditions) . ")";
            }
        } else {
            // Fallback for old method
            $stmt = $pdo->prepare("SELECT z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
            $stmt->execute([$userId]);
            $zoneNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($zoneNames)) {
                $conditions = [];
                foreach ($zoneNames as $zn) {
                    $zoneCommands = getZoneCommandsFromFormations($nis_formations, $zn);
                    $zoneConds = [];
                    foreach ($zoneCommands as $cmd) {
                        $zoneConds[] = "{$tableAlias}.{$columnName} LIKE ?";
                        $filterParams[] = "%" . $cmd . "%";
                        $searchTerms[] = $cmd;
                    }
                    if (!empty($zoneConds)) {
                        $conditions[] = "(" . implode(" OR ", $zoneConds) . ")";
                    }
                }
                if (!empty($conditions)) {
                    $filterSQL = " AND (" . implode(" OR ", $conditions) . ")";
                }
                $userZoneNames = $zoneNames;
            }
        }
    } catch (Exception $e) {}
    
    $searchTerms = array_values(array_unique($searchTerms));
    return ['sql' => $filterSQL, 'params' => $filterParams, 'zone_names' => $userZoneNames, 'search_terms' => $searchTerms];
}

/**
 * Get the list of formation/command names a user is authorized to POST officers to.
 * Returns null for unrestricted roles (Admin, SHQ Admin) - meaning no restriction applies.
 * Returns a flat array of allowed destination names for zone/command-scoped roles
 * (e.g. Command Admin restricted to their own assigned State Command).
 */
function getUserAllowedPostingDestinations($pdo, $userId, $nis_formations) {
    try {
        $stmt = $pdo->prepare("SELECT LOWER(r.name) as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt->execute([$userId]);
        $roleName = $stmt->fetchColumn();
    } catch (Exception $e) {
        $roleName = '';
    }

    if (in_array($roleName, ['admin', 'super admin', 'superadmin', 'shq admin', 'service hq', 'service headquarters'])) {
        return null;
    }

    $allowed = [];
    try {
        $stmt = $pdo->prepare("SELECT uz.assigned_command, z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
        $stmt->execute([$userId]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assignments as $assignment) {
            if (!empty($assignment['assigned_command'])) {
                // Restricted to this one specific Command only
                $allowed[] = $assignment['assigned_command'];
            } elseif (!empty($assignment['zone_name'])) {
                // Restricted to all Commands within this Zone
                $allowed = array_merge($allowed, getZoneCommandsFromFormations($nis_formations, $assignment['zone_name']));
            }
        }
    } catch (Exception $e) {}

    return array_values(array_unique($allowed));
}

/**
 * Check whether a given destination location string is authorized for the user,
 * based on the result of getUserAllowedPostingDestinations().
 * A null $allowedDestinations means unrestricted (always allowed).
 * An empty array means the user has no zone/command assignment (fail closed - blocked).
 */
function isDestinationAllowed($location, $allowedDestinations) {
    if ($allowedDestinations === null) return true;
    if (empty(trim((string)$location))) return false;
    if (empty($allowedDestinations)) return false;

    $locUpper = strtoupper(trim($location));
    foreach ($allowedDestinations as $allowed) {
        $allowedUpper = strtoupper(trim($allowed));
        if ($allowedUpper === '') continue;
        if ($locUpper === $allowedUpper || stripos($locUpper, $allowedUpper) !== false || stripos($allowedUpper, $locUpper) !== false) {
            return true;
        }
    }
    return false;
}