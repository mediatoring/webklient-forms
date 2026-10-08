# Webklient Forms

Formuláře pro WordPress od studia [Webklient.cz](https://www.webklient.cz/):
builder s podmíněnou logikou, vícekrokové formuláře, záznamy s exportem,
napojení na CRM a migrace z WPForms. Vyvinuto jako plnohodnotná náhrada
komerčních formulářových pluginů.

## Co plugin umí

**Builder.** Pole se skládají v editoru formuláře: text, e-mail, telefon,
víceřádkový text, číslo, datum, URL, skryté pole, rozbalovací nabídka,
zaškrtávací pole (jedna či více možností, svisle nebo vodorovně), nahrání
souborů, nadpis, obsahový blok s HTML a obrázkem, souhlas s podmínkami,
adresa s našeptáváním a ověřením v RÚIAN, IČO s doplněním z ARESu.
Každé pole má stabilní klíč, roli, nápovědu, placeholder, výchozí hodnotu
a volitelnou poloviční šířku.

**Podmíněná logika.** Režim „zobrazit, když" i „skrýt, když", libovolný počet
skupin pravidel (skupiny = NEBO, pravidla ve skupině = A) a deset operátorů.
Skryté pole se nevaliduje, neukládá ani neodesílá; kaskáda funguje přes
libovolný počet úrovní a server ji vyhodnocuje nezávisle na prohlížeči.

**Vícekrokové formuláře.** Zlom kroku se přidává jako pole, krok může mít
vlastní nadpis a popisky tlačítek, ukazatel postupu je textový nebo vizuální.
Bez JavaScriptu se formulář vykreslí jako jeden celek a zůstane odesílatelný.
Ovládání splňuje WCAG 2.2 AA včetně práce s fokusem a oznamování kroků.

**Ceníkové volby.** Volby mohou nést cenu a počet kusů; formulář spočítá
orientační cenu, server ji přepočítá z vlastní definice. Ceny lze načítat
i z meta pole vlastního typu příspěvku.

**Záznamy.** Každé odeslání se ukládá do administrace, filtruje podle
formuláře a exportuje do CSV i XLSX. Ke každé poptávce se ukládá kontext
návštěvy: zdroj (vyhledávač, sociální síť, kampaň, odkaz, přímý vstup), vstupní
stránka, odkud návštěvník přišel a cesta po webu před odesláním. Server sám při
odeslání vidí jen stránku s formulářem, proto se kontext sbírá už při prvním
načtení stránky.

**Doručení.** Notifikace s vlastním předmětem, odesílatelem a Reply-To,
automatická odpověď odesílateli, SMTP odesílání, webhook do CRM (Lead API)
s opakováním při výpadku, děkovací stránka nebo vlastní potvrzovací hláška.

**Log pošty.** Každý e-mail, který web odesílá, se zapisuje s časem, příjemcem,
předmětem, výsledkem a důvodem případného selhání – včetně pošty ostatních
pluginů. V administraci je výpis s filtrem a graf odeslaných a neúspěšných
zpráv za 7 nebo 30 dní; starší záznamy se samy mažou. Obsah zpráv ani přílohy
se neukládají. Zaznamenání úspěšných odeslání vyžaduje WordPress 5.9 a novější,
selhání se zapisují vždy.

**Ochrana.** Cloudflare Turnstile, kontrolní otázka, honeypot, limit odeslání
z jedné adresy, kontrola typu nahraných souborů a doba uchování záznamů
s automatickou skartací.

**Migrace z WPForms.** Importér převede formuláře z databáze webu i ze
souboru exportu včetně polí, voleb, podmínek a nastavení notifikací, převezme
původní shortcode a vypíše report rozdílů. Samostatný nástroj převede
i odeslané záznamy včetně příloh.

## Instalace

Nahrajte ZIP přes **Pluginy → Nahrát plugin**. Konfigurace je v
**Formuláře → Nastavení**; plugin funguje i bez vyplněných klíčů, jen
příslušné funkce zůstanou vypnuté.

## Automatické aktualizace

Plugin se aktualizuje z vydání (Releases) tohoto repozitáře a novou verzi
nabídne v přehledu pluginů jako kteroukoli jinou. Zdroj je zabudovaný
v pluginu (konstanta `WKF_UPDATE_REPO`), nenastavuje se a nepotřebuje token –
v **Nastavení → Aktualizace pluginu** je jen nainstalovaná verze a tlačítko
pro okamžitou kontrolu. Předběžná vydání (pre-release) se nenabízejí.

### Vydání nové verze

Stačí zvýšit `Version:` v hlavičce `webklient-forms.php` a změnu dostat do větve
`main`. Druhé místo s číslem už neexistuje – konstanta `WKF_VERSION` se čte
z hlavičky, takže se čísla nemohou rozejít.

O zbytek se postará workflow `.github/workflows/release.yml`: při každém push
do `main` přečte verzi, a není-li pro ni ještě vydání, sestaví ZIP se složkou
`webklient-forms/` a publikuje řádné vydání s tagem `v<verze>` a popisem změn
z commitů. Když vydání pro danou verzi už existuje, workflow neudělá nic,
opakovaný push tedy ničemu nevadí. Popis vydání se pak ukazuje jako seznam
změn v okně „Zobrazit podrobnosti“ u aktualizace.

## Soukromí

Plugin neodesílá data nikam mimo web. Výjimkou jsou služby, které si sami
zapnete a nakonfigurujete: našeptávání adres, ověření v RÚIAN, doplnění firmy
z ARESu, Turnstile, SMTP server a webhook do vašeho CRM. Kontext návštěvy
u poptávek se drží jen v prohlížeči návštěvníka (sessionStorage, žádné cookies)
a odešle se teprve s formulářem; uloží se k záznamu poptávky a smaže se s ním.
Provozovatel webu ho může navázat na cookie lištu, pokud nestaví na oprávněném
zájmu – slouží k tomu volba Vázat na souhlas v nastavení.

## Akademický výzkum

Plugin je zdarma. Kdo chce autorům oplatit, může zapojit web do doktorského
výzkumu na Slezské univerzitě v Opavě o tom, jak generativní AI čte české
weby: [geo.kubicek.ai/spoluprace](https://geo.kubicek.ai/spoluprace/).
Jde o **samostatný** plugin s vlastním poučením a souhlasem – Webklient Forms
sám žádná výzkumná data nesbírá ani neodesílá a účast není podmínkou
používání. Pozvánka v administraci se skryje, jakmile je výzkumný nástroj
na webu přítomen.

## Licence

MIT – viz [LICENSE](LICENSE). Vytvořilo studio
[Webklient.cz](https://www.webklient.cz/) (Mediatoring.com s.r.o.).
