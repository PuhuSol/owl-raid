// public_html/owlraid/js/wallet.js

let walletAddress = null;

async function connectWallet() {
    try {
        const { solana } = window;

        // Mobil kontrolü
        const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
        if (isMobile && (!solana || !solana.isPhantom)) {
            const currentUrl = window.location.href;
            window.location.href = `https://phantom.app/ul/browse/${encodeURIComponent(currentUrl)}`;
            return;
        }

        if (!solana || !solana.isPhantom) {
            alert("Phantom wallet is not installed!");
            window.open("https://phantom.app/", "_blank");
            return;
        }

        // Zaten bağlıysa çıkış yap
        if (walletAddress) {
            if (confirm("Disconnect wallet?")) {
                await solana.disconnect();
                walletAddress = null;
                updateWalletButton();
                // İsteğe bağlı: logout endpoint’i çağır
            }
            return;
        }

        // 1. Cüzdanı bağla
        const resp = await solana.connect();
        walletAddress = resp.publicKey.toString();

        // 2. Nonce al
        const nonceRes = await fetch('/owlraid/api/auth/nonce.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ wallet: walletAddress })
        });

        const nonceData = await nonceRes.json();
        if (!nonceData.success) {
            throw new Error(nonceData.error || 'Failed to get nonce');
        }

        const nonce = nonceData.nonce;

        // 3. Nonce’u imzala
        const encodedMessage = new TextEncoder().encode(nonce);
        const signedMessage = await solana.signMessage(encodedMessage, "utf8");

        // Signature’ı base58’e çevirmemiz gerekiyor
        const signatureBase58 = bs58.encode(signedMessage.signature);

        // 4. İmzayı doğrulat
        const verifyRes = await fetch('/owlraid/api/auth/verify.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                wallet: walletAddress,
                nonce: nonce,
                signature: signatureBase58
            })
        });

        const verifyData = await verifyRes.json();

        if (!verifyData.success) {
            throw new Error(verifyData.error || 'Signature verification failed');
        }

        // Başarılı giriş
        updateWalletButton();
        console.log("Login successful:", walletAddress);

        // Burada oyunu başlatma fonksiyonunu çağıracağız
        // checkPaymentStatus(); veya loadGame(); gibi

    } catch (err) {
        console.error(err);
        alert("Wallet connection failed: " + err.message);
        walletAddress = null;
        updateWalletButton();
    }
}

function updateWalletButton() {
    const btn = document.getElementById('wallet-btn');
    if (!btn) return;

    if (walletAddress) {
        btn.innerText = walletAddress.slice(0, 4) + "..." + walletAddress.slice(-4);
    } else {
        btn.innerText = "Connect Wallet";
    }
}

// Sayfa yüklendiğinde dinleyicileri kur
window.addEventListener('load', () => {
    const { solana } = window;
    if (solana && solana.isPhantom) {
        solana.on('connect', () => {
            walletAddress = solana.publicKey.toString();
            updateWalletButton();
        });
        solana.on('disconnect', () => {
            walletAddress = null;
            updateWalletButton();
        });
    }
});