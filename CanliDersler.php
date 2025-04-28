<?php

if (!file_exists("Baglanti.php")) {
    die("Sistem hatası: Yapılandırma dosyası bulunamadı");
}

// Geliştirme ortamında hata raporlamayı aç, üretimde kapat
if (getenv('APPLICATION_ENV') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

header('Content-Type: text/html; charset=utf-8');
define('CACHE_TIME', 10); // Cache süresini ihtiyaca göre ayarla (saniye)
define('CURL_TIMEOUT', 10); // cURL timeout süresini ihtiyaca göre ayarla (saniye)

// Veritabanı bağlantısı
include("Baglanti.php");
if (!isset($db) || !($db instanceof PDO)) {
    die("Veritabanı bağlantı hatası. Lütfen yöneticinizle iletişime geçin.");
}

if (!isset($_SESSION['YID'])) {
    header("Location: ../OturumAc");
    exit(); // exit eklenmeli
}

// Türkçe büyük harfe çevirme fonksiyonu
function turkce_buyuk_harf($metin) {
    $harfler = ['ı' => 'I', 'i' => 'İ', 'ğ' => 'Ğ', 'ü' => 'Ü', 'ş' => 'Ş', 'ö' => 'Ö', 'ç' => 'Ç'];
    return mb_strtoupper(strtr($metin, $harfler), 'UTF-8');
}

// Güvenli çıktı fonksiyonu
function safe_output(string $veri): string {
    return htmlspecialchars($veri, ENT_QUOTES, 'UTF-8');
}

// Organizasyonları ve sunucuları çekelim (optimize edilmiş sorgu)
try {
    $qOrg = $db->prepare("SELECT ID,ADI,LOGO FROM Organizasyonlar ORDER BY ADI ASC");
    $qOrg->execute();
    $organizasyonlar = $qOrg->fetchAll(PDO::FETCH_ASSOC);

    $qSrv = $db->prepare("SELECT * FROM Sunucular WHERE TIP = ?");
    $qSrv->execute(["BigBlueButton"]);
    $sunucular = $qSrv->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Veritabanı hatası: " . $e->getMessage());
    die("Veritabanı sorgu hatası oluştu. Lütfen daha sonra tekrar deneyin.");
}

// Sunucuları organizasyon bazlı gruplandır
$organizasyon_sunucular = [];
foreach ($sunucular as $sunucu) {
    $organizasyon_sunucular[$sunucu['OID']][] = $sunucu;
}

// cURL Multi ile API çağrıları
function download_pages_parallel($urls) {
    $multiCurl = [];
    $results = [];
    $mh = curl_multi_init();

    foreach ($urls as $key => $url) {
        $multiCurl[$key] = curl_init();
        curl_setopt($multiCurl[$key], CURLOPT_URL, $url);
        curl_setopt($multiCurl[$key], CURLOPT_RETURNTRANSFER, true);
        curl_setopt($multiCurl[$key], CURLOPT_TIMEOUT, CURL_TIMEOUT);
        curl_setopt($multiCurl[$key], CURLOPT_SSL_VERIFYPEER, false); // Basitlik için, production'da true yapın
        curl_multi_add_handle($mh, $multiCurl[$key]);
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh);
        }
    } while ($active && $status == CURLM_OK);

    foreach ($multiCurl as $key => $ch) {
        $results[$key] = curl_multi_getcontent($ch);
        if (curl_errno($ch)) {
            error_log("cURL hatası (Sunucu ID: $key): " . curl_error($ch));
            $results[$key] = ''; // Boş yanıt ata
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

// Sunucular için API bağlantılarını oluştur
$urls = [];
foreach ($sunucular as $sunucu) {
    $bbb_url = rtrim($sunucu['URL'], '/');
    $bbb_secret = $sunucu['SECRET'];
    $api_name = "getMeetings";
    $checksum = sha1($api_name . $bbb_secret);
    $url = "{$bbb_url}/api/{$api_name}?checksum={$checksum}";

    $urls[$sunucu['ID']] = $url;
}

// Cache mekanizması
$cache_dir = __DIR__ . '/cache';
$cache_file = $cache_dir . '/canliders_cache.json';

// Cache kontrolü ve oluşturma
try {
    if (!file_exists($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }

    if (file_exists($cache_file) && 
        (time() - filemtime($cache_file)) < CACHE_TIME && 
        is_readable($cache_file)) {
        $cached_data = file_get_contents($cache_file);
        $results = json_decode($cached_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Geçersiz cache verisi");
        }
    } else {
        $results = download_pages_parallel($urls);
        if (is_writable($cache_dir)) {
            file_put_contents($cache_file, json_encode($results));
        }
    }
} catch (Exception $e) {
    error_log("Cache hatası: " . $e->getMessage());
    $results = download_pages_parallel($urls); // Cache hatasında direkt API'den al
}

// Genel toplamları hesapla
$toplam_sunucu = 0;
$toplam_ders = 0;
$toplam_ogrenci = 0;
$aktif_organizasyonlar = [];

foreach ($organizasyonlar as $organizasyon) {
    $aktif_sunucu_sayisi = 0;
    $aktif_ders_sayisi = 0;
    $toplam_ogrenci_sayisi = 0;

    if (isset($organizasyon_sunucular[$organizasyon['ID']])) {
        foreach ($organizasyon_sunucular[$organizasyon['ID']] as $sunucu) {
            if (empty($results[$sunucu['ID']]) || trim($results[$sunucu['ID']]) == "BOŞ") {
                continue;
            }

            try {
                $oXML = new SimpleXMLElement($results[$sunucu['ID']]);
                $ders_bulundu = false;

                foreach ($oXML->meetings->meeting as $oEntrys) {
                    if (!empty($oEntrys->meetingName)) {
                        $aktif_ders_sayisi++;
                        $toplam_ogrenci_sayisi += (int)$oEntrys->participantCount;
                        $ders_bulundu = true;
                    }
                }

                if ($ders_bulundu) {
                    $aktif_sunucu_sayisi++;
                }
            } catch (Exception $e) {
                error_log("XML parse hatası (Sunucu ID: {$sunucu['ID']}): " . $e->getMessage());
                continue;
            }
        }
    }

    if ($aktif_ders_sayisi > 0) {
        $aktif_organizasyonlar[] = [
            'ID' => $organizasyon['ID'],
            'ADI' => $organizasyon['ADI'],
            'aktif_sunucu_sayisi' => $aktif_sunucu_sayisi,
            'aktif_ders_sayisi' => $aktif_ders_sayisi,
            'toplam_ogrenci_sayisi' => $toplam_ogrenci_sayisi
        ];
        $toplam_sunucu += $aktif_sunucu_sayisi;
        $toplam_ders += $aktif_ders_sayisi;
        $toplam_ogrenci += $toplam_ogrenci_sayisi;
    }
}


function download_page($path){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,$path);
    curl_setopt($ch, CURLOPT_FAILONERROR,1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION,1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $retValue = curl_exec($ch);          
    curl_close($ch);
	if($retValue) {
    return $retValue;
	}else{
		$retValue = "BOŞ";
    return $retValue;
	}
}

function moderatorurl($fullName,$meetingID,$moderatorPW,$bbb_url,$bbb_secret){

$mapi_name = "join";
$mparameter = "fullName=".$fullName."&meetingID=".$meetingID."&password=".$moderatorPW."&redirect=true";
$mchecksum = sha1($mapi_name . $mparameter . $bbb_secret);

$mquery = $mparameter . "&checksum=" . $mchecksum;

$murl = $bbb_url . "api/" . $mapi_name . "?" . $mquery;

return $murl;

}

function katilimciurl($fullName,$meetingID,$attendeePW,$bbb_url,$bbb_secret){

$kapi_name = "join";
$kparameter = "fullName=".$fullName."&meetingID=".$meetingID."&password=".$attendeePW."&redirect=true";
$kchecksum = sha1($kapi_name . $kparameter . $bbb_secret);

$kquery = $kparameter . "&checksum=" . $kchecksum;

$kurl = $bbb_url . "/api/" . $kapi_name . "?" . $kquery;

return $kurl;

}

function bitirurl($meetingID,$moderatorPW,$bbb_url,$bbb_secret){

$kapi_name = "end";
$kparameter = "meetingID=".$meetingID."&password=".$moderatorPW;
$kchecksum = sha1($kapi_name . $kparameter . $bbb_secret);

$kquery = $kparameter . "&checksum=" . $kchecksum;

$kurl = $bbb_url . "/api/" . $kapi_name . "?" . $kquery;

return $kurl;

}




?>
<!DOCTYPE html>

<?php include("head.php"); ?>
    <style>
        .accordion-button:not(.collapsed) {
            background-color: #f8f9fa;
        }
    </style>
	<?php if(isset($_SESSION['GIRIS'])=="OTO") { ?>
    <body onload="NewTab()">
	<?php unset($_SESSION['GIRIS']); }else{ ?>
    <body>
	<?php } ?>

        <!-- Begin page -->
        <div class="layout-wrapper">

            <!-- ========== Left Sidebar ========== -->
            <div class="main-menu">
				<?php include("menu.php"); ?>
            </div>
			
            <!-- Start Page Content here -->
            <div class="page-content">

                <!-- ========== Topbar Start ========== -->
				<?php include("topbar.php"); ?>
                <!-- ========== Topbar End ========== -->

                <div class="px-3">

                    <!-- Start Content-->
                    <div class="container-fluid">
                        
                        <!-- start page title -->
                        <div class="py-3 py-lg-4">
                            <div class="row mb-4">
                                <div class="col-lg-6">
                                    <h4 class="page-title mb-0">Canlı Dersler</h4>
                                </div>
                                <div class="col-lg-6">
                                   <div class="d-none d-lg-block">
                                    <ol class="breadcrumb m-0 float-end">
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">Vizyonveri</a></li>
                                        <li class="breadcrumb-item active">Canlı Dersler</li>
                                    </ol>
                                   </div>
                                </div>
                            </div>
							
							<!-- Bilgi Kartları -->
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="card text-center h-75">
                                        <div class="card-body">
                                            <div style="padding: 20px;">
                                                <h5 class="card-title">AKTİF SUNUCULAR</h5>
                                                <p class="card-text fs-3 fw-bold"><?= safe_output($toplam_sunucu) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card text-center h-75">
                                        <div class="card-body">
                                            <div style="padding: 20px;">
                                                <h5 class="card-title">AKTİF DERSLER</h5>
                                                <p class="card-text fs-3 fw-bold"><?= safe_output($toplam_ders) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card text-center h-75">
                                        <div class="card-body">
                                            <div style="padding: 20px;">
                                                <h5 class="card-title">AKTİF ÖĞRENCİLER</h5>
                                                <p class="card-text fs-3 fw-bold"><?= safe_output($toplam_ogrenci) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
							<div class="row">

								<div class="col-12" id="welcomeDiv" style="display:none;">
									<div class="card">
										<div class="card-body" style="background-color: #f5f7fa;">
											<h5 class="header-title">Canlı Ders Oluşturma Formu</h5>
											<p class="sub-header">Lütfen oluşturmak istediğiniz ders bilgilerini yazınız.</p>

											<form action="DersOlustur.php" method="POST">
												<div class="row">
													<?php if($_SESSION['YID']==1) { ?>
													<div class="col-md-12">
														<div class="form-floating mb-3">
															<select class="form-select" id="floatingSelectGrid" required name="OID" aria-label="Floating label select example">
																<option selected="">Seçim Yapınız...</option>
																<?php 

																	$q4 = $db->prepare("SELECT * FROM Organizasyonlar WHERE GORUNUM=? ORDER BY ADI ASC");  
																	$q4->execute(array(1)); 
																		if ($d4=$q4->fetchAll()){ 
																			foreach($d4 as $k4=>$v4) {
																
																?>
																<option value="<?php echo $v4['ID']; ?>"><?php echo $v4['ADI']; ?></option>
																<?php } } ?>
															</select>
															<label for="floatingSelectGrid">Dersin Açılacağı Üniversiteyi Seçiniz</label>
														</div>
													</div>
													<?php }else{ ?>
													<input type="hidden" name="OID" value="<?php echo $SESSIONOID; ?>">
													<?php } ?>
													
													<div class="col-md-12">
														<div class="form-floating mb-3">
															<input type="text" class="form-control" id="floatingnameInput" required name="meetingID" placeholder="Derslik Adı">
															<label for="floatingnameInput">Ders Adı</label>
														</div>
													</div>
													
													<div class="col-md-12">
														<div class="form-check form-switch mb-3">
															<input class="form-check-input" checked type="checkbox" name="GIRIS" value="1" id="flexSwitchCheckDefault">
															<label class="form-check-label" for="flexSwitchCheckDefault">Oluşturulan Derse Otomatik Giriş Yapılsın mı ?</label>
														</div>
													</div>
													
												</div>
												
												<div>
													<button type="submit" class="btn btn-primary w-md">Ders Oluştur</button>
												</div>
											</form>
										</div>
									</div>								
								</div>

								<div class="col-12"> 
								
                                        <?php if (empty($aktif_organizasyonlar)): ?>
                                            <div class="p-4 text-center text-dark alert alert-danger">AKTİF DERS BULUNMAMAKTADIR</div>
                                        <?php else: ?>
                                            <div class="accordion" id="organizasyonAccordion">
                                                <?php foreach ($organizasyonlar as $orgIndex => $organizasyon): ?>
                                                    <?php
                                                    $aktif_sunucu_sayisi = 0;
                                                    $aktif_ders_sayisi = 0;
                                                    $toplam_ogrenci_sayisi = 0;
                                
                                                    if (isset($organizasyon_sunucular[$organizasyon['ID']])) {
                                                        foreach ($organizasyon_sunucular[$organizasyon['ID']] as $sunucu) {
                                                            if (empty($results[$sunucu['ID']]) || trim($results[$sunucu['ID']]) == "BOŞ") {
                                                                continue;
                                                            }
                                
                                                            try {
                                                                $oXML = new SimpleXMLElement($results[$sunucu['ID']]);
                                                                $ders_bulundu = false;
                                
                                                                foreach ($oXML->meetings->meeting as $oEntrys) {
                                                                    if (!empty($oEntrys->meetingName)) {
                                                                        $aktif_ders_sayisi++;
                                                                        $toplam_ogrenci_sayisi += (int)$oEntrys->participantCount;
                                                                        $ders_bulundu = true;
                                                                    }
                                                                }
                                
                                                                if ($ders_bulundu) {
                                                                    $aktif_sunucu_sayisi++;
                                                                }
                                                            } catch (Exception $e) {
                                                                continue;
                                                            }
                                                        }
                                                    }
                                
                                                    if ($aktif_ders_sayisi == 0) continue;
                                                    ?>
                                
                                                    <div class="accordion-item">
                                                        <h3 class="accordion-header" id="heading<?= $orgIndex ?>">
                                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $orgIndex ?>">
                                                                <div class="row w-100">
                                                                    <div class="col-md-8 fw-bold text-uppercase align-self-center">
                                                                        <div class="align-middle" style=" padding-top: 8px; margin-right: 20px; ">
                                                                            <!--<img src="assets/logo/<?= safe_output($organizasyon['LOGO']) ?>" style="height: 50px; margin-right:20px">-->
                                                                            <?= safe_output(turkce_buyuk_harf($organizasyon['ADI'])) ?>
                                                                        </div>
                                                                    </div>
                                                                    
                                                                    <div class="col-md-4 text-end">
                                                                        <div style="padding-top: 2px; display: inline-flex; padding-right: 40px; ">
                                                                            
                                                                            <span class="badge d-flex align-items-center p-1 pe-2 text-primary-emphasis border border-dark-subtle">
                                                                                <span class="menu-text" style=" width: 30px; font-size: 12px; font-weight: 600; "> <?= safe_output($aktif_sunucu_sayisi) ?> </span>
                                                                                <span class="vr" style="margin-right: .5rem !important;"></span>
                                                                                <span class="menu-icon text-dark"><i stroke-width="1" width="20" data-feather="server"></i></span>
                                                                            </span>
                                                                            
                                                                            <div class="align-middle" style="margin-right: 15px;border-left: 1px solid #ababab7d;margin-left: 15px;"></div>
                                                                            
                                                                            <span class="badge d-flex align-items-center p-1 pe-2 text-primary-emphasis border border-dark-subtle">
                                                                                <span class="menu-text" style=" width: 30px; font-size: 12px; font-weight: 600; "> <?= safe_output($aktif_ders_sayisi) ?> </span>
                                                                                <span class="vr" style="margin-right: .5rem !important;"></span>
                                                                                <span class="menu-icon text-dark"><i stroke-width="1" width="20" data-feather="cast"></i></span>
                                                                            </span>
                                                                            
                                                                            <div class="align-middle" style="margin-right: 15px;border-left: 1px solid #ababab7d;margin-left: 15px;"></div>
                                                                            
                                                                            <span class="badge d-flex align-items-center p-1 pe-2 text-primary-emphasis border border-dark-subtle">
                                                                                <span class="menu-text" style=" width: 30px; font-size: 12px; font-weight: 600; "> <?= safe_output($toplam_ogrenci_sayisi) ?> </span>
                                                                                <span class="vr" style="margin-right: .5rem !important;"></span>
                                                                                <span class="menu-icon text-dark"><i stroke-width="1" width="20" data-feather="users"></i></span>
                                                                            </span>
  
                                                                        </div>

                                                                    </div>
                                                                </div>
                                                            </button>
                                                        </h3>
                                                        <div id="collapse<?= $orgIndex ?>" class="accordion-collapse collapse" data-bs-parent="#organizasyonAccordion">
                                                            <div class="accordion-body">
                                                                <div class="accordion" id="sunucuAccordion<?= $orgIndex ?>">
                                                                    <?php foreach ($organizasyon_sunucular[$organizasyon['ID']] as $sunucuIndex => $sunucu): ?>
                                                                        <?php
                                                                        if (empty($results[$sunucu['ID']]) || trim($results[$sunucu['ID']]) == "BOŞ") {
                                                                            continue;
                                                                        }
                                
                                                                        try {
                                                                            $oXML = new SimpleXMLElement($results[$sunucu['ID']]);
                                                                            $ders_var_mi = false;
                                                                            $ders_sayisi = 0;
                                                                            $ogrenci_sayisi = 0;
                                
                                                                            foreach ($oXML->meetings->meeting as $oEntrys) {
                                                                                if (!empty($oEntrys->meetingName)) {
                                                                                    $ders_var_mi = true;
                                                                                    $ders_sayisi++;
                                                                                    $ogrenci_sayisi += (int)$oEntrys->participantCount;
                                                                                }
                                                                            }
                                                                            if (!$ders_var_mi) continue;
                                                                        } catch (Exception $e) {
                                                                            continue;
                                                                        }
                                                                        ?>
                                
                                                                        <div class="accordion-item">
                                                                            <h2 class="accordion-header">
                                                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSunucu<?= $orgIndex ?>_<?= $sunucuIndex ?>">
                                                                                    <div class="row w-100">
                                                                                        
                                                                                        <div class="col-md-8">
                                                                                            
                                                                                            <span class="badge d-flex align-items-center p-1 pe-2 text-primary-emphasis border border-dark-subtle" style="border-color: #fff !important;width:50%">
                                                                                                <span class="menu-icon text-dark"><i stroke-width="1" width="20" data-feather="server"></i></span>
                                                                                                <span class="vr" style="margin-right: .5rem;margin-left: .5rem;"></span>
                                                                                                <span class="menu-text" style=" width: 30px; font-size: 12px; font-weight: 600; "> </i> <?= safe_output($sunucu['ADI']) ?></span>
                                                                                            </span>
                                                                                            
                                                                                        </div>
                                                                                        
                                                                                        <div class="col-md-4 text-end">
                                                                                            <div style="padding-top: 2px; display: inline-flex; padding-right: 20px; ">
                                                                                            
                                                                                            <span class="badge d-flex align-items-center p-1 pe-2 text-primary-emphasis border border-dark-subtle">
                                                                                                <span class="menu-text" style=" width: 30px; font-size: 12px; font-weight: 600; "> <?= safe_output($ders_sayisi) ?> </span>
                                                                                                <span class="vr" style="margin-right: .5rem !important;"></span>
                                                                                                <span class="menu-icon text-dark"><i stroke-width="1" width="20" data-feather="cast"></i></span>
                                                                                            </span>
                                                                                            
                                                                                            <div class="align-middle" style="margin-right: 15px;border-left: 1px solid #ababab7d;margin-left: 15px;"></div>
                                                                                            
                                                                                            <span class="badge d-flex align-items-center p-1 pe-2 text-primary-emphasis border border-dark-subtle">
                                                                                                <span class="menu-text" style=" width: 30px; font-size: 12px; font-weight: 600; "><?= safe_output($ogrenci_sayisi) ?></span>
                                                                                                <span class="vr" style="margin-right: .5rem !important;"></span>
                                                                                                <span class="menu-icon text-dark"><i stroke-width="1" width="20" data-feather="users"></i></span>
                                                                                            </span>
                                                                                            
                                                                                            </div>

                                                                                        </div>
                                                                                    </div>
                                                                                </button>
                                                                            </h2>
                                                                            <div id="collapseSunucu<?= $orgIndex ?>_<?= $sunucuIndex ?>" class="accordion-collapse collapse" data-bs-parent="#sunucuAccordion<?= $orgIndex ?>">
                                                                                <div class="accordion-body">
                                                                                    
                                                                                    <table class="table table-striped dt-responsive nowrap w-100">
                                        												<thead>
                                        													<tr>
                                        														<th>Ders Adı</th>
                                        														<th class="text-center">Durum</th>
                                        														<th class="text-center">Katılımcı</th>
                                        														<th class="text-center">Başlangıç</th>
                                        														<th style="text-align: end;">İşlemler</th>
                                        													</tr>
                                        												</thead>
                                        												
                                        												<tbody style="vertical-align: middle;">
                                        												    
                                                                                            <?php foreach ($oXML->meetings->meeting as $oEntrys): ?>
                                                                                                <?php if (!empty($oEntrys->meetingName)): ?>
                                                                                                
                                                                                                <?php 
                                                                                                
                                                                                                $meetingID = rawurlencode($oEntrys->meetingID);
                        																		$fullName = rawurlencode($_SESSION['AD']." ".$_SESSION['SOYAD']);
                        																		$moderatorPW = $oEntrys->moderatorPW;
                        																		$attendeePW = $oEntrys->attendeePW;
                                                                                                
                                                                                                
                                                                                                ?>
                                                                                                
                                                                                                <tr>
                                                                                                    
                                                                                                    <td>
                                                                                                        <span><?= safe_output($oEntrys->meetingName) ?></span>
                                                                                                    </td>
                                                                                                    
                                                                                                    <td class="text-center">
                                                                                                        <?php if($oEntrys->running=="true") { ?>
                                                                                                            <span style="font-size:12px;font-weight: 100;" class="badge bg-success ms-auto">Aktif Ders</span>
                                                                                                        <?php } ?>
                                                                                                        <?php if($oEntrys->running=="false") { ?>
                                                                                                            <span style="font-size:12px;font-weight: 100;" class="badge bg-warning ms-auto">Katılımcı Bekleniyor</span>
                                                                                                        <?php } ?>
                                                                                                    </td>
                                                                                                    
                                                                                                    <td class="text-center">
                                                                                                        <span style="font-size:12px;font-weight: 100;" class="badge bg-primary ms-auto">
                                                                                                            <?= safe_output($oEntrys->participantCount) ?>  Kişi
                                                                                                        </span>
                                                                                                    </td>
                                                                                                    
                                                                                                   <td class="text-center">
                                                                                                        <span><?= date("d.m.Y H:i:s", substr(safe_output($oEntrys->startTime), 0, 10)) ?></span>
                                                                                                    </td>
                                                                                                    
                                                                                                    <td style="text-align: end;">
                                            															<button class="openPopup btn btn-xs btn-info " type="button" data-bs-toggle="offcanvas" data-href="getMeetingInfo.php?DSID=<?= safe_output($sunucu['ID']) ?>&MID=<?= safe_output($meetingID) ?>" data-bs-target="#offcanvasRight" aria-controls="offcanvasRight">Bilgi</button>
                                            															<a href="<?php echo moderatorurl($fullName,$meetingID,$moderatorPW,$bbb_url,$bbb_secret); ?>" target="blank" class="btn btn-warning  btn-xs waves-effect waves-light">Moderator</a>
                                            															<a href="<?php echo katilimciurl($fullName,$meetingID,$attendeePW,$bbb_url,$bbb_secret); ?>" target="blank" class="btn btn-secondary  btn-xs waves-effect waves-light">Katılımcı</a>
                                            
                                            															<a data-bs-toggle="modal" data-bs-target="#danger-alert-modal<?php echo $oEntrys->internalMeetingID; ?>" target="blank" class="btn btn-danger btn-xs waves-effect waves-light">Bitir</a>
                                            															
                                            															<!-- Danger Alert Modal -->
                                            															<div id="danger-alert-modal<?php echo $oEntrys->internalMeetingID; ?>" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
                                            																<div class="modal-dialog modal-md">
                                            																	<div class="modal-content modal-filled bg-danger">
                                            																		<div class="modal-body p-4">
                                            																			<div class="text-center">
                                            																				<i class="bx bx-aperture h1 text-white"></i>
                                            																				<h4 class="mt-2 text-white" style="text-wrap: balance;">TOPLANTI SONRALNDIRMA !</h4>
                                            																				<p class="mt-3 text-white"  style="text-wrap: balance;"><?php echo $oEntrys->meetingName; ?> adlı toplantıyı sonlandırmak üzeresiniz. Bunu yapmak istediğinizden emin misiniz ?</p>
                                            																				<button type="button" class="btn btn-light my-2" onclick="location.href='DersiBitir.php?DSID=<?= safe_output($sunucu['ID']) ?>&MID=<?php echo $meetingID; ?>&password=<?php echo $moderatorPW; ?>';">Evet, Sonlandır</button>
                                            																				<button type="button" class="btn btn-light my-2" data-bs-dismiss="modal">Hayır, Vazgeç</button>
                                            																			</div>
                                            																		</div>
                                            																	</div><!-- /.modal-content -->
                                            																</div><!-- /.modal-dialog -->
                                            															</div><!-- /.modal -->
                                            															<div id="infos-alert-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
                                            																<div class="modal-dialog modal-sm">
                                            																	<div class="modal-content">
                                            																		<div class="modal-body p-4">
                                            																			<div class="text-center">
                                            																				<i class="bx bxs-info-circle h1 text-info"></i>
                                            																				<h4 class="mt-2">TOPLANTI KATILIM LİNKLERİ</h4>
                                            																				<p class="mt-3">Aktif toplantıya katılım türünüzü seçip toplantıya katılabilirsiniz.</p>
                                            																				<a href="<?php echo moderatorurl($fullName,$meetingID,$moderatorPW,$bbb_url,$bbb_secret); ?>" target="blank" class="btn btn-success btn-xs waves-effect waves-light">Moderator</a>
                                            																				<a href="<?php echo katilimciurl($fullName,$meetingID,$attendeePW,$bbb_url,$bbb_secret); ?>" target="blank" class="btn btn-primary btn-xs waves-effect waves-light">Katılımcı</a>
                                            																			</div>
                                            																		</div>
                                            																	</div><!-- /.modal-content -->
                                            																</div><!-- /.modal-dialog -->
                                            															</div><!-- /.modal -->
                                            															
                                            														</td>
                                                                                                    
                                                                                                </tr>
                                                                                                
                                                                                                
                                                                                                <?php endif; ?>
                                                                                            <?php endforeach; ?>
                                                                                    
                                                                                        </tbody>
											                                        </table>
                                                                                    
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

											
											<!-- offcanvas Right -->
											<div class="offcanvas offcanvas-end" data-bs-scroll="true" data-bs-backdrop="true" tabindex="-1" id="offcanvasRight" aria-labelledby="offcanvasRightLabel">
												<div class="offcanvas-header">
													<h5 id="offcanvasRightLabel"style=" padding-top: 15px; padding-left: 10px; ">TOPLANTI BİLGİLERİ</h5>
													<button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
												</div>
												<div class="offcanvas-body" style="overflow-y: clip !important;">
												</div>
											</div>
											

								</div><!-- end col-->

								
							</div>
							
                        </div>
                        <!-- end page title -->  
                        
                    </div> <!-- container -->

                </div> <!-- content -->

                <!-- Footer Start -->
				<?php include("footer.php"); ?>
                <!-- end Footer -->

            </div>
            <!-- End Page content -->


        </div>
