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
formuláře a exportuje do CSV i XLSX. Volitelně se ukládá kontext návštěvy:
vstupní stránka, zdroj, kampaň a cesta po webu před odesláním.

**Doručení.** Notifikace s vlastním předmětem, odesílatelem a Reply-To,
automatická odpověď odesílateli, SMTP odesílání, webhook do CRM (Lead API)
s opakováním při výpadku, děkovací stránka nebo vlastní potvrzovací hláška.

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
nabídne v přehledu pluginů jako kteroukoli jinou. Repozitář je předvyplněný
v **Nastavení → Aktualizace pluginu**, kde je i tlačítko pro okamžitou
kontrolu. U privátního repozitáře se doplňuje přístupový token, který se
ukládá šifrovaně.

### Vydání nové verze

1. Zvyšte `Version:` v hlavičce `webklient-forms.php` i konstantu `WKF_VERSION`.
2. Vytvořte vydání s tagem `v2.4.0` nebo `2.4.0` – číslo musí odpovídat hlavičce.
3. Volitelně přiložte ZIP se složkou `webklient-forms/`; jinak se použije
   zdrojový archiv, složku si plugin při instalaci sám pojmenuje správně.
4. Popis vydání se zobrazí jako seznam změn v okně „Zobrazit podrobnosti".

## Soukromí

Plugin neodesílá data nikam mimo web. Výjimkou jsou služby, které si sami
zapnete a nakonfigurujete: našeptávání adres, ověření v RÚIAN, doplnění firmy
z ARESu, Turnstile, SMTP server a webhook do vašeho CRM. Kontext návštěvy
u poptávek se drží jen v prohlížeči návštěvníka a odešle se s formulářem.

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
