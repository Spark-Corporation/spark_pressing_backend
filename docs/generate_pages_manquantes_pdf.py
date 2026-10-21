"""PDF des pages sparkpressing absentes du frontend pressing.senousolutions.com."""
from __future__ import annotations

import os
from datetime import date

from reportlab.lib.colors import HexColor, white
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import HRFlowable, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

OUT_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "pages-manquantes-pressing.pdf")

NAVY = HexColor("#0F2744")
TEAL = HexColor("#0E7490")
RED = HexColor("#B91C1C")
ORANGE = HexColor("#C2410C")
AMBER = HexColor("#B45309")
LIGHT = HexColor("#F8FAFC")
BORDER = HexColor("#E2E8F0")
MUTED = HexColor("#64748B")
ROW_ALT = HexColor("#F1F5F9")
INK = HexColor("#1E293B")
GREEN = HexColor("#047857")


def styles():
    s = getSampleStyleSheet()
    s.add(ParagraphStyle(name="DocTitle", fontName="Helvetica-Bold", fontSize=18, textColor=NAVY, spaceAfter=3 * mm, leading=22))
    s.add(ParagraphStyle(name="DocSub", fontName="Helvetica", fontSize=9.5, textColor=MUTED, spaceAfter=6 * mm, leading=13))
    s.add(ParagraphStyle(name="Section", fontName="Helvetica-Bold", fontSize=12, textColor=NAVY, spaceBefore=5 * mm, spaceAfter=2.5 * mm, leading=15))
    s.add(ParagraphStyle(name="Body", fontName="Helvetica", fontSize=9, textColor=INK, leading=12, spaceAfter=2 * mm))
    s.add(ParagraphStyle(name="Small", fontName="Helvetica", fontSize=8, textColor=MUTED, leading=10))
    s.add(ParagraphStyle(name="Cell", fontName="Helvetica", fontSize=8, textColor=INK, leading=10))
    s.add(ParagraphStyle(name="P0", fontName="Helvetica-Bold", fontSize=7.5, textColor=RED, leading=9))
    s.add(ParagraphStyle(name="P1", fontName="Helvetica-Bold", fontSize=7.5, textColor=ORANGE, leading=9))
    s.add(ParagraphStyle(name="P2", fontName="Helvetica-Bold", fontSize=7.5, textColor=AMBER, leading=9))
    return s


def header_footer(canvas, doc):
    canvas.saveState()
    canvas.setFillColor(NAVY)
    canvas.rect(0, A4[1] - 11 * mm, A4[0], 11 * mm, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont("Helvetica-Bold", 8)
    canvas.drawString(15 * mm, A4[1] - 7 * mm, "SPARK PRESSING")
    canvas.setFont("Helvetica", 8)
    canvas.drawRightString(A4[0] - 15 * mm, A4[1] - 7 * mm, "Pages manquantes vs sparkpressing")
    canvas.setStrokeColor(BORDER)
    canvas.line(15 * mm, 13 * mm, A4[0] - 15 * mm, 13 * mm)
    canvas.setFillColor(MUTED)
    canvas.setFont("Helvetica", 7.5)
    canvas.drawString(15 * mm, 7 * mm, "Source : menus sparkpressing  ·  Cible : pressing.senousolutions.com")
    canvas.drawRightString(A4[0] - 15 * mm, 7 * mm, f"Page {doc.page}")
    canvas.restoreState()


def table(st, headers, rows, widths):
    data = [[Paragraph(f'<font color="white"><b>{h}</b></font>', st["Cell"]) for h in headers]]
    for row in rows:
        cells = []
        for i, val in enumerate(row):
            t = str(val)
            if i == 0 and t.startswith("P0"):
                cells.append(Paragraph(t, st["P0"]))
            elif i == 0 and t.startswith("P1"):
                cells.append(Paragraph(t, st["P1"]))
            elif i == 0 and t.startswith("P2"):
                cells.append(Paragraph(t, st["P2"]))
            else:
                cells.append(Paragraph(t, st["Cell"]))
        data.append(cells)
    tbl = Table(data, colWidths=widths, repeatRows=1)
    cmds = [
        ("BACKGROUND", (0, 0), (-1, 0), NAVY),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 3),
        ("RIGHTPADDING", (0, 0), (-1, -1), 3),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("GRID", (0, 0), (-1, -1), 0.35, BORDER),
        ("BOX", (0, 0), (-1, -1), 0.7, NAVY),
    ]
    for i in range(1, len(data)):
        if i % 2 == 0:
            cmds.append(("BACKGROUND", (0, i), (-1, i), ROW_ALT))
    tbl.setStyle(TableStyle(cmds))
    return tbl


def section_title(st, title, count):
    return Paragraph(f"{title} <font color='#64748B'>({count})</font>", st["Section"])


def main():
    st = styles()
    story = []

    # --- Pages manquantes dérivées STRICTEMENT des menus sparkpressing ---
    # Critère : page présente dans menu.blade / menu2 / menu3 (ou doc imprimable
    # liée à un flux menu) SANS équivalent dédié sur pressing.senousolutions.com.
    # Les écrans déjà couverts (dashboard, dépôts liste/nouveau, clients liste/nouveau,
    # atelier consolidé, retraits, caisse, articles, rapports hub, multi-agences,
    # équipe, sync, paramètres hub) ne sont PAS listés.

    staff = [
        # Configurations
        ["P0", "Configurations", "Ajouter Suffixe Code", "codesuffixes.create", "Aucun écran suffixes"],
        ["P0", "Configurations", "Liste des suffixes", "codesuffixes.index", "Aucun écran suffixes"],
        ["P0", "Configurations", "Ajouter Heure de retrait", "deliveryhours.create", "Pas de CRUD créneaux"],
        ["P0", "Configurations", "Heures de retraits", "deliveryhours.index", "Pas de liste créneaux"],
        ["P1", "Configurations", "Ajouter une promotion", "promos.create", "Partiel via /parametres/promotions"],
        ["P1", "Configurations", "Liste des promotions", "promos.index", "Partiel via /parametres/promotions"],
        # Clients fidèles
        ["P0", "Clients Fidèles", "Fidéliser Client", "clientgroups.create", "Pas d'écran dédié"],
        ["P0", "Clients Fidèles", "Liste des clients fidèles", "clientgroups.index", "Pas d'écran dédié"],
        ["P0", "Clients Fidèles", "Liste des meilleurs clients", "clientgroups.best_clients", "Pas de classement dédié"],
        # Groupes
        ["P0", "Groupes de Fidélisation", "Ajouter Groupe", "loyalgroups.create", "Pas de CRUD groupes"],
        ["P0", "Groupes de Fidélisation", "Liste des groupes", "loyalgroups.index", "Pas de liste groupes"],
        # Dépôts sous-pages
        ["P0", "Gestion des Dépôts", "Liste des Dépôts à terme", "deposit_search", "Absent de /depots"],
        ["P0", "Gestion des Dépôts", "Clients inactifs", "search_clients", "Absent de /clients"],
        ["P1", "Gestion des Dépôts", "Dépôt — Facture", "deposits.facture", "Document printable absent"],
        ["P1", "Gestion des Dépôts", "Dépôt — Ticket caisse", "deposits.ticket", "Document printable absent"],
        ["P1", "Gestion des Dépôts", "Dépôt — Bon", "deposits.bon", "Document printable absent"],
        ["P1", "Gestion des Dépôts", "Dépôt — Retrait partiel (écran)", "deposits.retrieve", "Flux partiel via /retraits/:id"],
        ["P2", "Gestion des Dépôts", "Dépôts en attente", "onwaiting / deposits.onwaiting", "Pas de vue dédiée"],
        # Retraits
        ["P1", "Gestion des Retraits", "Retraits faits aujourd'hui", "onday", "Pas de vue filtrée dédiée"],
        ["P0", "Gestion des Retraits", "Retrait sur une période", "search_retrieve", "Recherche période absente"],
        # Entrées/Sorties
        ["P1", "Entrées/Sorties", "Recherche sur une période", "in_out.search", "Filtre période caisse absent"],
        ["P2", "Entrées/Sorties", "Créer / éditer un mouvement", "in_out.create / edit", "Partiel /caisse/nouveau"],
        # Bilan
        ["P0", "Bilan", "Recherche sur Vente", "search", "Pas d'écran ventes période"],
        ["P0", "Bilan", "Remises sur commandes", "searchDiscount", "Pas d'écran remises"],
        ["P0", "Bilan", "Dépôt par client", "getCustomerDeposit", "Pas d'écran dédié"],
        ["P0", "Bilan", "Etat des ventes du jour", "saleDay", "Pas d'écran dédié caissier/jour"],
        ["P1", "Bilan", "Recherche bilan journalier", "searchDaily", "Partiel /rapports/bilan"],
        ["P0", "Bilan", "Retrait du jour (stats)", "totake", "Stats retraits jour absentes"],
        ["P0", "Bilan", "Stats des commandes", "range", "Pas d'écran stats commandes"],
        # Recettes
        ["P0", "Recettes", "Recette Journalière", "receipeNewToDay", "Pas d'écran recettes dédié"],
        ["P0", "Recettes", "Recette Générale Journalier", "receipeAllToDay", "Pas d'écran recettes générale"],
        ["P0", "Recettes", "Commandes soldées", "searchReceipt", "Pas d'écran soldées"],
        ["P0", "Recettes", "Impayés", "searchLeftpay", "Pas d'écran impayés dédié"],
        ["P1", "Recettes", "Promos spéciales", "promoSpec (stub legacy)", "Lien vide dans sparkpressing"],
        # Etats
        ["P0", "Gestion des Etats", "Ajouter Etat", "status.create", "Pas de CRUD états linge"],
        ["P0", "Gestion des Etats", "Liste des Etats", "status.index", "Pas de liste états"],
        # Clients
        ["P0", "Gestion des Clients", "Etat des Clients", "search_clients_state", "Nouveaux clients / période absent"],
        # RH métier séparés
        ["P1", "Gestion des Caissiers", "Ajouter un Caissier", "tellers.create", "Fusionné partiellement dans /equipe"],
        ["P1", "Gestion des Caissiers", "Liste des Caissiers", "tellers.index", "Pas de vue rôle Caissier"],
        ["P1", "Gestion des Laveurs", "Ajouter un Laveur", "laveurs.create", "Fusionné partiellement dans /equipe"],
        ["P1", "Gestion des Laveurs", "Liste des Laveurs", "laveurs.index", "Pas de vue rôle Laveur"],
        ["P1", "Gestion des Classeurs", "Ajouter un Classeur", "classeurs.create", "Fusionné partiellement dans /equipe"],
        ["P1", "Gestion des Classeurs", "Liste des Classeurs", "classeurs.index", "Pas de vue rôle Classeur"],
        # Agences / users (sous-écrans)
        ["P2", "Gestion des Agences", "Ajouter une agence", "agences.create", "Partiel /parametres/organisation"],
        ["P2", "Gestion des utilisateurs", "Nouvel utilisateur", "users.create", "Partiel /equipe"],
        # Statistiques
        ["P0", "Statistiques", "Classement client", "getRatioClient", "Pas d'écran ranking"],
        # Logs
        ["P0", "Logs d'impression", "Logs", "logs.index", "/audit sidebar = 404"],
        ["P0", "Logs d'impression", "Recherche sur les logs", "log.search", "Recherche impressions absente"],
        ["P0", "Actions Systèmes", "Logs Système", "logs_sys.index", "/audit sidebar = 404"],
        # Atelier — vues séparées legacy (nouveau site a un kanban unique)
        ["P2", "Gestion du linge", "En attente de traitement (vue liste)", "traitements.waiting", "Couvert partiellement par /atelier"],
        ["P2", "Gestion du linge", "En cours de traitement (vue liste)", "traitements.in_progress", "Couvert partiellement par /atelier"],
        ["P2", "Gestion du linge", "Traité (vue liste)", "traitements.treated", "Couvert partiellement par /atelier"],
        ["P2", "Traitement", "En attente classement", "traitements.treated_all", "Couvert partiellement par /atelier"],
        ["P2", "Traitement", "Classé (vue liste)", "traitements.classed", "Couvert partiellement par /atelier"],
        # Auth staff
        ["P0", "Auth staff", "Login staff", "login (/admin/login)", "/login = 404"],
        ["P0", "Auth staff", "Mot de passe oublié", "password.request", "/mot-de-passe-oublie = 404"],
        ["P1", "Auth staff", "Register staff", "register", "Pas d'écran inscription staff"],
    ]

    client = [
        ["P0", "Portail client", "Dashboard client", "clientarea", "Espace client absent"],
        ["P0", "Portail client", "Mon profil", "profil-client", "Absent"],
        ["P0", "Portail client", "Changer mot de passe", "setting-client", "Absent"],
        ["P0", "Portail client", "Mes dépôts", "clientdeposit", "Absent"],
        ["P0", "Portail client", "Mes retraits", "clientwithdraw", "Absent"],
        ["P0", "Portail client", "Détail dépôt client", "client-show", "Absent"],
        ["P0", "Auth client", "Login client", "client.login", "Absent"],
        ["P0", "Auth client", "Register client", "client.register", "Absent"],
    ]

    superadmin = [
        ["P0", "Superadmin", "Dashboard", "superadmin.dashboard", "Console SA absente"],
        ["P1", "Superadmin", "Mon profil", "superadmin.profil.index", "Absent"],
        ["P1", "Superadmin", "Changer mot de passe", "superadmin.setting", "Absent"],
        ["P0", "Superadmin", "Ajouter une agence", "superadmin.agences.create", "Absent"],
        ["P0", "Superadmin", "Liste des agences", "superadmin.agences.index", "Absent"],
        ["P0", "Superadmin", "Rapport consolidé", "superadmin.agencies.recap", "Absent"],
        ["P0", "Superadmin", "Nouvel utilisateur", "superadmin.users.create", "Absent"],
        ["P0", "Superadmin", "Utilisateurs", "superadmin.users.index", "Absent"],
        ["P0", "Superadmin", "Ajouter un pressing", "superadmin.pressings.create", "Absent"],
        ["P0", "Superadmin", "Liste des pressings", "superadmin.pressings.index", "Absent"],
        ["P1", "Superadmin", "Login superadmin", "superadmin/login", "Absent"],
    ]

    covered = [
        ["Dashboard staff", "dashboard", "/dashboard"],
        ["Mon profil / MDP staff", "profils.index / setting", "/parametres/profil"],
        ["Liste des Dépôts", "get_list_deposits", "/depots"],
        ["Nouveau Dépôt", "checkcustomer → deposits.create", "/depots/nouveau"],
        ["Détail dépôt (maquette)", "deposits.show", "/depots/:code"],
        ["Liste des Retraits", "deliveries.index", "/retraits"],
        ["Traiter un retrait", "deliveries / retrieve", "/retraits/:code"],
        ["Liste des mouvements caisse", "in_out.index", "/caisse"],
        ["Nouveau mouvement", "in_out.create", "/caisse/nouveau"],
        ["Articles (liste + create)", "articles.*", "/articles (+ /nouveau)"],
        ["Clients (liste + create)", "clients.*", "/clients (+ /nouveau)"],
        ["Permissions / Rôles", "admin.permissions / roles", "/equipe/permissions"],
        ["Utilisateurs (annuaire)", "users.index", "/equipe"],
        ["Agences (vue réseau)", "agences.index", "/multi-agences + organisation"],
        ["Atelier (consolidé)", "traitements.*", "/atelier"],
        ["Rapports (hub + bilan)", "stats / receipts (partiel)", "/rapports + /rapports/bilan"],
        ["Promotions / fidélité (settings)", "promos / loyalgroups (partiel)", "/parametres/promotions"],
        ["Synchronisation", "(nouveau)", "/synchronisation"],
        ["Paramètres hub", "configurations (partiel)", "/parametres/*"],
    ]

    n_staff = len(staff)
    n_client = len(client)
    n_sa = len(superadmin)
    n_total = n_staff + n_client + n_sa
    n_p0 = sum(1 for r in staff + client + superadmin if r[0] == "P0")
    n_p1 = sum(1 for r in staff + client + superadmin if r[0] == "P1")
    n_p2 = sum(1 for r in staff + client + superadmin if r[0] == "P2")

    story.append(Paragraph("Pages manquantes selon sparkpressing", st["DocTitle"]))
    story.append(
        Paragraph(
            f"Inventaire basé sur les menus <b>sparkpressing</b> "
            f"(<font face='Courier'>menu.blade.php</font>, "
            f"<font face='Courier'>menu2.blade.php</font>, "
            f"<font face='Courier'>menu3.blade.php</font>) "
            f"et les écrans documents liés aux dépôts.<br/>"
            f"Cible : <b>https://pressing.senousolutions.com/</b> · "
            f"Date : {date.today().strftime('%d/%m/%Y')}<br/>"
            f"Règle : une page sparkpressing est « manquante » si le nouveau site "
            f"n'offre pas d'équivalent dédié (même si une maquette voisine existe).",
            st["DocSub"],
        )
    )
    story.append(HRFlowable(width="100%", thickness=1, color=TEAL, spaceAfter=3 * mm))

    story.append(Paragraph("1. Synthèse", st["Section"]))
    summary = [
        [
            Paragraph('<font color="white"><b>Total manquantes</b></font>', st["Cell"]),
            Paragraph('<font color="white"><b>P0</b></font>', st["Cell"]),
            Paragraph('<font color="white"><b>P1</b></font>', st["Cell"]),
            Paragraph('<font color="white"><b>P2</b></font>', st["Cell"]),
        ],
        [
            Paragraph(f"<b>{n_total}</b><br/>Staff {n_staff} · Client {n_client} · SA {n_sa}", st["Cell"]),
            Paragraph(f"<b>{n_p0}</b><br/>Bloquant / métier cœur", st["Cell"]),
            Paragraph(f"<b>{n_p1}</b><br/>Important / partiel", st["Cell"]),
            Paragraph(f"<b>{n_p2}</b><br/>Consolidable", st["Cell"]),
        ],
    ]
    box = Table(summary, colWidths=[45 * mm, 40 * mm, 40 * mm, 40 * mm])
    box.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (0, 0), NAVY),
                ("BACKGROUND", (1, 0), (1, 0), RED),
                ("BACKGROUND", (2, 0), (2, 0), ORANGE),
                ("BACKGROUND", (3, 0), (3, 0), AMBER),
                ("BACKGROUND", (0, 1), (-1, 1), LIGHT),
                ("BOX", (0, 0), (-1, -1), 0.7, BORDER),
                ("INNERGRID", (0, 0), (-1, -1), 0.35, BORDER),
                ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
                ("TOPPADDING", (0, 0), (-1, -1), 5),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
                ("LEFTPADDING", (0, 0), (-1, -1), 5),
            ]
        )
    )
    story.append(box)
    story.append(Spacer(1, 2 * mm))
    story.append(
        Paragraph(
            f"<b>{len(covered)} pages sparkpressing déjà couvertes</b> "
            f"par le nouveau frontend (listées en fin de document — non à refaire).",
            st["Body"],
        )
    )

    story.append(section_title(st, "2. Staff — pages manquantes (menu.blade.php)", n_staff))
    story.append(
        table(
            st,
            ["Prio", "Section menu", "Page sparkpressing", "Route / name", "Écart vs nouveau site"],
            staff,
            [11 * mm, 32 * mm, 42 * mm, 40 * mm, 40 * mm],
        )
    )

    story.append(section_title(st, "3. Portail client — pages manquantes (menu2.blade.php)", n_client))
    story.append(
        table(
            st,
            ["Prio", "Section menu", "Page sparkpressing", "Route / name", "Écart vs nouveau site"],
            client,
            [11 * mm, 32 * mm, 42 * mm, 40 * mm, 40 * mm],
        )
    )

    story.append(section_title(st, "4. Superadmin — pages manquantes (menu3.blade.php)", n_sa))
    story.append(
        table(
            st,
            ["Prio", "Section menu", "Page sparkpressing", "Route / name", "Écart vs nouveau site"],
            superadmin,
            [11 * mm, 32 * mm, 42 * mm, 40 * mm, 40 * mm],
        )
    )

    story.append(section_title(st, "5. Déjà présentes sur le nouveau site (ne pas refaire)", len(covered)))
    story.append(
        Paragraph(
            "Ces écrans sparkpressing ont un équivalent maquette sur "
            "pressing.senousolutions.com (reste le branchement API).",
            st["Body"],
        )
    )
    story.append(
        table(
            st,
            ["Page sparkpressing", "Route legacy", "Équivalent live"],
            covered,
            [55 * mm, 60 * mm, 50 * mm],
        )
    )

    story.append(Paragraph("6. Ordre de complétion recommandé", st["Section"]))
    order = [
        ["1", "Auth staff (+ reset)", "Débloquer l'accès"],
        ["2", "Recettes + Impayés + Bilans P0", "Parity caisse / pilotage legacy"],
        ["3", "Configurations (suffixes, heures, états)", "Paramétrage opérationnel"],
        ["4", "Fidélité (groupes + clients fidèles + ranking)", "CRM legacy"],
        ["5", "Dépôts à terme + clients inactifs + docs ticket/facture", "Ops dépôt"],
        ["6", "Logs système + impression (/audit)", "Sidebar déjà prévue"],
        ["7", "Portail client (menu2)", "Si scope consumer"],
        ["8", "Superadmin (menu3)", "Si multi-pressing"],
    ]
    story.append(
        table(st, ["#", "Lot (issu de sparkpressing)", "Pourquoi"], order, [10 * mm, 95 * mm, 60 * mm])
    )

    story.append(Spacer(1, 4 * mm))
    story.append(HRFlowable(width="100%", thickness=0.5, color=BORDER, spaceAfter=2 * mm))
    story.append(
        Paragraph(
            "Sources : D:/sparkpressing/resources/views/inc/menu.blade.php, menu2.blade.php, "
            "menu3.blade.php + routes web (documents dépôt). "
            "Audit croisé avec les routes live pressing.senousolutions.com.",
            st["Small"],
        )
    )

    doc = SimpleDocTemplate(
        OUT_PATH,
        pagesize=A4,
        leftMargin=14 * mm,
        rightMargin=14 * mm,
        topMargin=18 * mm,
        bottomMargin=18 * mm,
        title="Pages manquantes selon sparkpressing",
        author="Audit UI Spark Pressing",
    )
    doc.build(story, onFirstPage=header_footer, onLaterPages=header_footer)
    print(OUT_PATH)
    print(f"OK bytes={os.path.getsize(OUT_PATH)} total_manquantes={n_total} P0={n_p0} P1={n_p1} P2={n_p2}")


if __name__ == "__main__":
    main()
