package mx.com.avba.caseta;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

import java.util.ArrayList;
import java.util.List;

/** Casos de tarjetas: qué renglón es el nombre y cuál número es el código. */
public class AnalizadorTarjetaTest {

    private static List<AnalizadorTarjeta.Linea> tarjeta(Object... x) {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        int y = 10;
        for (int i = 0; i < x.length; i += 2) {
            int alto = (Integer) x[i + 1];
            l.add(new AnalizadorTarjeta.Linea((String) x[i], y, 20, alto));
            y += alto + 12;
        }
        return l;
    }

    private static void ver(List<AnalizadorTarjeta.Linea> l, AnalizadorTarjeta.Reglas r, String codigo, String nombre) {
        AnalizadorTarjeta.Lectura le = AnalizadorTarjeta.analizar(l, r);
        assertEquals("código", codigo, le.codigo);
        assertEquals("nombre", nombre, le.nombre);
    }

    @Test public void gafeteConEtiqueta() {
        ver(tarjeta("QUANTUM ENERGY", 60, "JUAN PÉREZ LÓPEZ", 44, "TÉCNICO DE MANTENIMIENTO", 26, "No. Empleado: 004512", 26, "Vigencia: 31/12/2027", 22),
            new AnalizadorTarjeta.Reglas(), "004512", "Juan Pérez López");
    }

    @Test public void numeroSoloYTelefono() {
        ver(tarjeta("Quantum", 50, "Energy", 30, "MARÍA DE LA LUZ HERNÁNDEZ", 40, "12345678", 38, "TEL. EMERGENCIA 5512345678", 20),
            new AnalizadorTarjeta.Reglas(), "12345678", "María de la Luz Hernández");
    }

    @Test public void erroresDeLectura() {
        ver(tarjeta("QUANTUM ENERGY", 60, "ROBERTO SÁNCHEZ RUIZ", 40, "ID 1O25S", 30), new AnalizadorTarjeta.Reglas(), "10255", "Roberto Sánchez Ruiz");
    }

    @Test public void etiquetaNombreArriba() {
        ver(tarjeta("QUANTUM ENERGY MÉXICO", 50, "NOMBRE", 20, "CARLOS RUIZ ORTEGA", 30, "PUESTO", 20, "SUPERVISOR DE SEGURIDAD", 30, "FICHA", 20, "88231", 34),
            new AnalizadorTarjeta.Reglas(), "88231", "Carlos Ruiz Ortega");
    }

    @Test public void curpNssYFechasNoSonCodigo() {
        ver(tarjeta("QUANTUM", 60, "LUIS FLORES DÍAZ", 40, "CURP PEGJ800101HDFRRN09", 20, "No. 7788", 30), new AnalizadorTarjeta.Reglas(), "7788", "Luis Flores Díaz");
        ver(tarjeta("Quantum Energy", 50, "Ernesto Ramírez Vela", 40, "NSS 12345678901", 20, "CP 76000", 20, "Gafete 31415", 30),
            new AnalizadorTarjeta.Reglas(), "31415", "Ernesto Ramírez Vela");
        ver(tarjeta("QUANTUM ENERGY", 60, "PATRICIA GÓMEZ", 40, "VIGENCIA 2027", 20, "Expedición 12/05/2025", 20, "Empleado 56789", 30),
            new AnalizadorTarjeta.Reglas(), "56789", "Patricia Gómez");
    }

    @Test public void puestoNoEsNombre() {
        ver(tarjeta("QUANTUM ENERGY", 60, "INGENIERO DE PROYECTOS", 44, "No. 2210", 30), new AnalizadorTarjeta.Reglas(), "2210", null);
    }

    @Test public void reglasAjustables() {
        AnalizadorTarjeta.Reglas r6 = new AnalizadorTarjeta.Reglas();
        r6.longitud = 6;
        ver(tarjeta("QUANTUM ENERGY", 60, "SERGIO VEGA CRUZ", 40, "1234", 30, "Empleado 004512", 30, "Tel 4421234567", 20), r6, "004512", "Sergio Vega Cruz");
        AnalizadorTarjeta.Reglas rp = new AnalizadorTarjeta.Reglas();
        rp.ponerPatron("QE-?(\\d{5})");
        ver(tarjeta("QUANTUM ENERGY", 60, "RAÚL PEÑA SOTO", 40, "QE-20481", 30, "4421234567", 20), rp, "20481", "Raúl Peña Soto");
    }

    @Test public void nombreEnDosRenglones() {
        ver(tarjeta("QUANTUM ENERGY", 60, "JUAN CARLOS", 40, "PÉREZ LÓPEZ", 40, "No. 4471", 28), new AnalizadorTarjeta.Reglas(), "4471", "Juan Carlos Pérez López");
        ver(tarjeta("QUANTUM ENERGY", 60, "JUAN PÉREZ LÓPEZ", 40, "SUPERVISOR", 40, "Ficha 9921", 26), new AnalizadorTarjeta.Reglas(), "9921", "Juan Pérez López");
        ver(tarjeta("QUANTUM", 60, "MARÍA FERNANDA", 42, "RÍOS", 42, "Empleado 55120", 26), new AnalizadorTarjeta.Reglas(), "55120", "María Fernanda Ríos");
        ver(tarjeta("QUANTUM ENERGY", 60, "ANA LÓPEZ CRUZ", 40, "PLANTA SAN JUAN", 20, "No. 8080", 26), new AnalizadorTarjeta.Reglas(), "8080", "Ana López Cruz");
    }

    @Test public void letrerosDeIdentificacionNoSonNombre() {
        ver(tarjeta("SERGIO IBARRA MONTES", 36, "NACIONALIDAD", 22, "MEXICANA", 30, "No. 27716004", 28), new AnalizadorTarjeta.Reglas(), "27716004", "Sergio Ibarra Montes");
        ver(tarjeta("MEXICANA NACIONALIDAD", 40, "Juan Pérez", 34, "ID 4471", 28), new AnalizadorTarjeta.Reglas(), "4471", "Juan Pérez");
        ver(tarjeta("FECHA DE NACIMIENTO", 30, "DOMICILIO", 20, "LAURA SOTO RIOS", 30, "Folio 99120", 26), new AnalizadorTarjeta.Reglas(), "99120", "Laura Soto Rios");
    }

    @Test public void estabilizadorPideVariasLecturasIguales() {
        AnalizadorTarjeta.Estabilizador e = new AnalizadorTarjeta.Estabilizador(3, 6);
        assertNull(e.agregar(new AnalizadorTarjeta.Lectura("12345", "Juan Pérez", 50)));
        assertNull(e.agregar(new AnalizadorTarjeta.Lectura(null, null, 0)));
        assertNull(e.agregar(new AnalizadorTarjeta.Lectura("12845", "Juan Pérez", 50)));
        assertNull(e.agregar(new AnalizadorTarjeta.Lectura("12345", null, 50)));
        AnalizadorTarjeta.Lectura ok = e.agregar(new AnalizadorTarjeta.Lectura("12345", "Juan Perez", 50));
        assertNotNull(ok);
        assertEquals("12345", ok.codigo);
    }

    /** La cámara a ratos da imágenes borrosas o con reflejo (sin ID): 3 lecturas iguales entre 10 bastan (antes, entre 6). */
    @Test public void toleraImagenesBorrosasEntreLasBuenas() {
        AnalizadorTarjeta.Estabilizador e = new AnalizadorTarjeta.Estabilizador(3, 10);
        AnalizadorTarjeta.Lectura nada = new AnalizadorTarjeta.Lectura(null, null, 0), buena = new AnalizadorTarjeta.Lectura("01287", null, 60);
        AnalizadorTarjeta.Lectura[] serie = { buena, nada, nada, nada, buena, nada, nada, nada, buena };
        AnalizadorTarjeta.Lectura ok = null;
        for (AnalizadorTarjeta.Lectura l : serie) ok = e.agregar(l);
        assertNotNull("3 buenas entre 9 imágenes", ok);
        assertEquals("01287", ok.codigo);
        AnalizadorTarjeta.Estabilizador seis = new AnalizadorTarjeta.Estabilizador(3, 6);
        ok = null;
        for (AnalizadorTarjeta.Lectura l : serie) ok = seis.agregar(l);
        assertNull("con 6 no alcanzaba", ok);
    }

    @Test public void nombreLeidoSinElCodigoTambienCuenta() {
        AnalizadorTarjeta.Estabilizador e = new AnalizadorTarjeta.Estabilizador(2, 6);
        e.agregar(new AnalizadorTarjeta.Lectura(null, "Juan Pérez López", 0));
        e.agregar(new AnalizadorTarjeta.Lectura("4471", null, 50));
        AnalizadorTarjeta.Lectura ok = e.agregar(new AnalizadorTarjeta.Lectura("4471", null, 50));
        assertNotNull(ok);
        assertNull(ok.nombre);
        assertEquals("Juan Pérez López", e.nombreProbable("4471"));
    }

    /* ── Credencial de acceso (vertical). Datos ficticios con el mismo formato:
          ID en recuadro, «NOMBRE:» con el nombre en dos renglones, EMPRESA, CARGO,
          NSS de 11 dígitos, TIPO DE SANGRE, ALERGIAS y «CREDENCIAL DE ACCESO». ── */
    private static AnalizadorTarjeta.Linea ln(String t, int arriba, int izq, int alto, int ancho) {
        return new AnalizadorTarjeta.Linea(t, arriba, izq, alto, ancho);
    }
    private static final String NOMBRE_CRED = "Ramiro Esteban Villarreal Duarte";

    /** El reconocimiento lee cada etiqueta con su dato en un solo renglón. */
    @Test public void credencialEtiquetaYDatoJuntos() {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        l.add(ln("SHOT ON REDMI NOTE 12", 690, 600, 12, 150));
        l.add(ln("ID: 00287", 825, 560, 70, 370));
        l.add(ln("NOMBRE: RAMIRO ESTEBAN", 970, 190, 42, 860));
        l.add(ln("VILLARREAL DUARTE", 1025, 510, 42, 580));
        l.add(ln("EMPRESA: MONTAJES DEL NORTE", 1105, 190, 42, 1000));
        l.add(ln("CARGO: AYUDANTE", 1210, 190, 42, 540));
        l.add(ln("NSS: 12345678901", 1325, 190, 40, 480));
        l.add(ln("TIPO DE SANGRE: O+", 1440, 190, 42, 580));
        l.add(ln("ALERGIAS: NO", 1550, 190, 42, 420));
        l.add(ln("CREDENCIAL DE ACCESO", 1770, 370, 48, 820));
        ver(l, new AnalizadorTarjeta.Reglas(), "00287", NOMBRE_CRED);
    }

    /** Etiquetas y datos en renglones separados (a la misma altura); el dato a veces queda «arriba» de su etiqueta. */
    @Test public void credencialEtiquetasAparte() {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        l.add(ln("ID:", 828, 560, 64, 110));
        l.add(ln("00287", 825, 700, 70, 230));
        l.add(ln("NOMBRE:", 972, 190, 40, 270));
        l.add(ln("RAMIRO ESTEBAN", 968, 510, 42, 520));
        l.add(ln("VILLARREAL DUARTE", 1025, 510, 42, 580));
        l.add(ln("EMPRESA:", 1107, 190, 40, 280));
        l.add(ln("MONTAJES DEL NORTE", 1104, 515, 42, 640));
        l.add(ln("CARGO:", 1212, 190, 40, 210));
        l.add(ln("AYUDANTE", 1210, 440, 42, 290));
        l.add(ln("NSS:", 1327, 190, 40, 140));
        l.add(ln("12345678901", 1324, 345, 40, 320));
        l.add(ln("TIPO DE SANGRE:", 1442, 190, 40, 480));
        l.add(ln("O+", 1440, 700, 42, 70));
        l.add(ln("ALERGIAS:", 1552, 190, 40, 290));
        l.add(ln("NO", 1550, 525, 42, 75));
        l.add(ln("CREDENCIAL DE ACCESO", 1770, 370, 48, 820));
        ver(l, new AnalizadorTarjeta.Reglas(), "00287", NOMBRE_CRED);
    }

    /** Errores típicos: «ID:0O287» pegado y con letra O, «N0MBRE» con cero, «0+» en el tipo de sangre. */
    @Test public void credencialConErroresDeLectura() {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        l.add(ln("ID:0O287", 825, 560, 70, 360));
        l.add(ln("N0MBRE: RAMIRO ESTEBAN", 970, 190, 42, 860));
        l.add(ln("VILLARREAL DUARTE", 1025, 510, 42, 580));
        l.add(ln("EMPRESA: MONTAJES DEL NORTE", 1105, 190, 42, 1000));
        l.add(ln("CARGO: AYUDANTE", 1210, 190, 42, 540));
        l.add(ln("NSS: 12345678901", 1325, 190, 40, 480));
        l.add(ln("TIPO DE SANGRE: 0+", 1440, 190, 42, 580));
        l.add(ln("CREDENCIAL DE ACCESO", 1770, 370, 48, 820));
        ver(l, new AnalizadorTarjeta.Reglas(), "00287", NOMBRE_CRED);
    }

    /** En esa imagen no salió la etiqueta «NOMBRE»: los dos renglones del nombre se juntan igual. */
    @Test public void credencialSinLeerLaEtiqueta() {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        l.add(ln("ID: 00287", 825, 560, 70, 370));
        l.add(ln("RAMIRO ESTEBAN", 970, 510, 42, 520));
        l.add(ln("VILLARREAL DUARTE", 1025, 510, 42, 580));
        l.add(ln("EMPRESA:", 1107, 190, 40, 280));
        l.add(ln("MONTAJES DEL NORTE", 1104, 515, 42, 640));
        l.add(ln("NSS:", 1327, 190, 40, 140));
        l.add(ln("12345678901", 1324, 345, 40, 320));
        l.add(ln("CREDENCIAL DE ACCESO", 1770, 370, 48, 820));
        ver(l, new AnalizadorTarjeta.Reglas(), "00287", NOMBRE_CRED);
    }

    /** Si en esa imagen no se leyó el ID, el NSS (aunque quede separado de su etiqueta) no se toma como código. */
    @Test public void nssNoEsCodigo() {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        l.add(ln("NOMBRE:", 972, 190, 40, 270));
        l.add(ln("RAMIRO ESTEBAN", 968, 510, 42, 520));
        l.add(ln("NSS:", 1327, 190, 40, 140));
        l.add(ln("12345678901", 1324, 345, 40, 320));
        AnalizadorTarjeta.Lectura le = AnalizadorTarjeta.analizar(l, new AnalizadorTarjeta.Reglas());
        assertNull("el NSS no es el código", le.codigo);
        assertEquals("Ramiro Esteban", le.nombre);
    }

    /** Nombre de un solo renglón con etiqueta: no se le pega la empresa de abajo. */
    @Test public void credencialNombreDeUnRenglon() {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        l.add(ln("ID: 00412", 825, 560, 70, 370));
        l.add(ln("NOMBRE: ANA SOFIA RUIZ PAEZ", 970, 190, 42, 960));
        l.add(ln("EMPRESA:", 1052, 190, 40, 280));
        l.add(ln("MONTAJES DEL NORTE", 1050, 515, 42, 640));
        l.add(ln("CARGO: AYUDANTE", 1150, 190, 42, 540));
        ver(l, new AnalizadorTarjeta.Reglas(), "00412", "Ana Sofia Ruiz Paez");
    }

    @Test public void etiquetas() {
        assertEquals("RAMIRO ESTEBAN", AnalizadorTarjeta.despuesDeNombre("NOMBRE: RAMIRO ESTEBAN"));
        assertEquals("", AnalizadorTarjeta.despuesDeNombre("Nombre(s):"));
        assertEquals("RAMIRO", AnalizadorTarjeta.despuesDeNombre("N0MBRE RAMIRO"));
        assertNull(AnalizadorTarjeta.despuesDeNombre("RAMIRO ESTEBAN"));
        assertTrue(AnalizadorTarjeta.esEtiqueta("TIPO DE SANGRE:"));
        assertTrue(AnalizadorTarjeta.esEtiqueta("EMPRESA:"));
        assertTrue(AnalizadorTarjeta.esEtiqueta("1D:"));
        assertFalse(AnalizadorTarjeta.esEtiqueta("CREDENCIAL DE ACCESO"));
        assertFalse(AnalizadorTarjeta.esEtiqueta("NO"));
        assertFalse(AnalizadorTarjeta.esEtiqueta("VILLARREAL DUARTE"));
    }

    /** «VILLARREAL DUART3» / «C0RONA»: un número dentro de una palabra del nombre se lee como letra. */
    @Test public void numeroDentroDelNombre() {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        l.add(ln("ID: 00287", 825, 560, 70, 370));
        l.add(ln("NOMBRE: RAMIRO E5TEBAN", 970, 190, 42, 860));
        l.add(ln("VILLARREAL DUART3", 1025, 510, 42, 580));
        l.add(ln("EMPRESA: MONTAJES DEL NORTE", 1105, 190, 42, 1000));
        // «3» no es una letra que se confunda: ese renglón no se junta y queda el primero corregido
        ver(l, new AnalizadorTarjeta.Reglas(), "00287", "Ramiro Esteban");
        l.set(2, ln("VILLARREAL DU4RTE C0RONA", 1025, 510, 42, 640));
        ver(l, new AnalizadorTarjeta.Reglas(), "00287", "Ramiro Esteban");          // «4» dentro: no se junta
        l.set(2, ln("V1LLARREAL C0RONA", 1025, 510, 42, 580));
        ver(l, new AnalizadorTarjeta.Reglas(), "00287", "Ramiro Esteban Villarreal Corona");
    }

    /** Un número de 11 dígitos suelto (el NSS sin su etiqueta) no se toma como código. */
    @Test public void onceDigitosSueltosNoSonCodigo() {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        l.add(ln("NOMBRE: RAMIRO ESTEBAN", 970, 190, 42, 860));
        l.add(ln("12345678901", 1324, 345, 40, 320));
        assertNull(AnalizadorTarjeta.analizar(l, new AnalizadorTarjeta.Reglas()).codigo);
        AnalizadorTarjeta.Reglas r11 = new AnalizadorTarjeta.Reglas();
        r11.longitud = 11;                                                             // si el código sí fuera de 11
        assertEquals("12345678901", AnalizadorTarjeta.analizar(l, r11).codigo);
    }

    /* ── Credenciales con rayas blancas de la impresora (cabezal con inyectores tapados): rayas verticales que
          cruzan el recuadro del ID y parten las etiquetas («N OMBRE:», «N SS:», «E VIPRESA:»). Datos ficticios. ── */
    private static List<AnalizadorTarjeta.Linea> conRayas(AnalizadorTarjeta.Linea... id) {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        for (AnalizadorTarjeta.Linea x : id) l.add(x);
        l.add(ln("N OMBRE: RAMIRO ESTEBAN", 970, 190, 42, 860));
        l.add(ln("VILLARREAL DUARTE", 1025, 510, 42, 580));
        l.add(ln("E VIPRESA: MONTAJES DEL NORTE", 1105, 190, 42, 1000));
        l.add(ln("CAR(GO: AYUDANTE GENERAL", 1210, 190, 42, 700));
        l.add(ln("N SS: 12345678901", 1325, 190, 40, 480));
        l.add(ln("T IPO DE SANGRE: O+", 1440, 190, 42, 580));
        l.add(ln("CREDENCIAL DE ACCESO", 1770, 370, 48, 820));
        return l;
    }
    private static AnalizadorTarjeta.Reglas credencial(String... base) {
        AnalizadorTarjeta.Reglas r = new AnalizadorTarjeta.Reglas();
        r.digitosId = 5;
        r.minDigitos = 5;
        r.ponerIds(base);
        return r;
    }

    /** La raya dentro del recuadro: se lee como «|», «I», espacio o un dígito repetido; el ID sale completo. */
    @Test public void idConRayasDeLaImpresora() {
        String[] lecturas = { "ID: 00287", "ID: 0 0287", "ID: 0|0287", "ID: 0I0287", "ID: 0028 7", "ID: 002|87",
                              "ID: 00287 |", "ID:| 00287", "1D: 0 0287", "ID: 010287" };
        for (String t : lecturas) {
            AnalizadorTarjeta.Lectura le = AnalizadorTarjeta.analizar(conRayas(ln(t, 825, 560, 70, 370)), credencial());
            assertEquals("ID leído como «" + t + "»", "00287", le.codigo);
            assertEquals("nombre con «N OMBRE:» partida", NOMBRE_CRED, le.nombre);
        }
        // El número en pedazos, en renglones separados de la misma fila
        ver(conRayas(ln("ID: 0", 825, 560, 70, 150), ln("0287", 826, 760, 68, 200)), credencial(), "00287", NOMBRE_CRED);
        ver(conRayas(ln("ID:", 828, 560, 64, 110), ln("002", 825, 700, 70, 120), ln("87", 825, 850, 70, 80)), credencial(), "00287", NOMBRE_CRED);
        // Sin la etiqueta «ID»: el número solo, partido
        ver(conRayas(ln("0 0287", 825, 700, 70, 230)), credencial(), "00287", NOMBRE_CRED);
    }

    /** La raya también cruza el último dígito: cortado, se parece a una letra («4» → «A», «0» → «C»). Si se pierde, no se adivina. */
    @Test public void ultimoDigitoCortadoPorLaRaya() {
        for (String t : new String[] { "ID: 0|028A", "ID: 0 028 A", "ID: 0028A", "ID: 0I028A" })
            assertEquals("ID leído como «" + t + "»", "00284", AnalizadorTarjeta.analizar(conRayas(ln(t, 825, 560, 70, 370)), credencial()).codigo);
        assertEquals("00280", AnalizadorTarjeta.analizar(conRayas(ln("ID: 0 028C", 825, 560, 70, 370)), credencial()).codigo);
        assertEquals("00287", AnalizadorTarjeta.analizar(conRayas(ln("ID: 0028T", 825, 560, 70, 370)), credencial()).codigo);
        // «4» que quedó como «L»: falta un dígito; aunque 28 esté en la base, no se toma
        assertNull(AnalizadorTarjeta.analizar(conRayas(ln("ID: 0|028L", 825, 560, 70, 370)), credencial("28", "284")).codigo);
    }

    /** La raya sólo agrega una línea blanca: nunca repite un «0». Un «0» de más no se adivina. */
    @Test public void unCeroDeMasNoSeAdivina() {
        assertNull(AnalizadorTarjeta.analizar(conRayas(ln("ID: 000287", 825, 560, 70, 370)), credencial()).codigo);
    }

    /** Como en la credencial real: una raya pegada a la izquierda del «1» (2.º número) y otra pegada a la derecha del
     *  último. Leídas como «1», «l» o «|» de más, sin la base del personal (la persona puede no estar). ID inventado. */
    @Test public void rayasPegadasAlUnoYAlUltimo() {
        for (String t : new String[] { "ID: 01287", "ID: 011287", "ID: 012871", "ID: 0112871", "ID: 0l12871", "ID: 0|12871",
                                       "ID: 0 12871", "ID: 0I1287I", "ID: 01287|", "ID: 0|1287|" }) {
            AnalizadorTarjeta.Lectura le = AnalizadorTarjeta.analizar(conRayas(ln(t, 825, 560, 70, 370)), credencial());
            assertEquals("ID leído como «" + t + "»", "01287", le.codigo);
        }
        // Lo que se leyó en el recuadro queda para decírselo al guardia
        assertEquals("0112871", AnalizadorTarjeta.analizar(conRayas(ln("ID: 0112871", 825, 560, 70, 370)), credencial()).enRecuadro);
        // La etiqueta «ID» mal leída («IO:») con el número partido por la raya
        assertEquals("01287", AnalizadorTarjeta.analizar(conRayas(ln("IO: 0 1287", 825, 560, 70, 370)), credencial()).codigo);
        // Un «1» real al principio no se quita por estar en la orilla: 10213 con un «1» de más en medio sigue en duda
        assertNull(AnalizadorTarjeta.analizar(conRayas(ln("ID: 101213", 825, 560, 70, 370)), credencial()).codigo);
    }

    /** Si la raya se lee como un «1» y no se sabe cuál es, decide la base del personal; sin base no se adivina. */
    @Test public void rayaDudosaLaDecideLaBase() {
        List<AnalizadorTarjeta.Linea> l = conRayas(ln("ID: 101213", 825, 560, 70, 370));   // 10213 con un «1» de más
        assertNull("sin base: podría ser 01213, 10213 o 10123", AnalizadorTarjeta.analizar(l, credencial()).codigo);
        assertEquals("10213", AnalizadorTarjeta.analizar(l, credencial("287", "10213", "4471")).codigo);
        assertNull("ninguna en la base: tampoco se adivina", AnalizadorTarjeta.analizar(l, credencial("287")).codigo);
    }

    /** Si la raya borró un dígito, no se toma el pedazo: «0028» sería otra persona (la 28). */
    @Test public void pedazoDelIdNoEsOtraPersona() {
        assertNull(AnalizadorTarjeta.analizar(conRayas(ln("ID: 0028", 825, 560, 70, 300)), credencial("28", "287")).codigo);
        assertNull(AnalizadorTarjeta.analizar(conRayas(ln("ID: 0028", 825, 560, 70, 150), ln("|", 825, 760, 70, 10)), credencial("28")).codigo);
    }

    /** «N SS:» partida no es «N.» (número): el NSS nunca es el ID, con o sin la regla de 5 dígitos. */
    @Test public void nssConEtiquetaPartida() {
        assertNull(AnalizadorTarjeta.analizar(conRayas(), new AnalizadorTarjeta.Reglas()).codigo);
        assertNull(AnalizadorTarjeta.analizar(conRayas(), credencial()).codigo);
        List<AnalizadorTarjeta.Linea> l = conRayas(ln("ID: 02 87", 825, 560, 70, 370));
        assertEquals("0287", AnalizadorTarjeta.analizar(l, new AnalizadorTarjeta.Reglas()).codigo);
        List<AnalizadorTarjeta.Linea> aparte = new ArrayList<>();
        aparte.add(ln("N SS:", 1327, 190, 40, 140));
        aparte.add(ln("12345", 1324, 345, 40, 160));                                       // un pedazo del NSS
        aparte.add(ln("678901", 1324, 520, 40, 190));
        assertNull(AnalizadorTarjeta.analizar(aparte, credencial()).codigo);
    }

    @Test public void etiquetasPartidasPorRayas() {
        assertEquals("RAMIRO ESTEBAN", AnalizadorTarjeta.despuesDeNombre("N OMBRE: RAMIRO ESTEBAN"));
        assertEquals("RAMIRO", AnalizadorTarjeta.despuesDeNombre("NOM|BRE: RAMIRO"));
        assertEquals("RAMIRO", AnalizadorTarjeta.despuesDeNombre("NOVIBRE: RAMIRO"));
        assertNull(AnalizadorTarjeta.despuesDeNombre("NORBERTO RUIZ"));
        assertTrue(AnalizadorTarjeta.esEtiqueta("N SS:"));
        assertTrue(AnalizadorTarjeta.esEtiqueta("E VIPRESA:"));
        assertTrue(AnalizadorTarjeta.esEtiqueta("T IPO DE SANGRE:"));
        assertTrue(AnalizadorTarjeta.esEtiqueta("ALEF RGIAS:"));
        assertTrue(AnalizadorTarjeta.esEtiqueta("CAR(GO:"));
        assertFalse(AnalizadorTarjeta.esEtiqueta("VILLARREAL DUARTE"));
        assertFalse(AnalizadorTarjeta.esEtiqueta("MONTAJES DEL NORTE"));
        assertFalse(AnalizadorTarjeta.esEtiqueta("ANA SOFIA"));
        assertEquals("287", AnalizadorTarjeta.sinCeros("00287"));
        assertEquals("0", AnalizadorTarjeta.sinCeros("00000"));
        // Lo que sigue al número en el recuadro no se vuelve número («SOLO» no es «5010»)
        assertEquals("00287", AnalizadorTarjeta.codigoDelId(" 00287 SOLO", 5, new AnalizadorTarjeta.Reglas()));
        assertEquals("00287", AnalizadorTarjeta.codigoDelId(" O 0287", 5, new AnalizadorTarjeta.Reglas()));
        assertNull(AnalizadorTarjeta.codigoDelId(" SOLO", 5, new AnalizadorTarjeta.Reglas()));
    }

    /** Si unas imágenes leyeron sólo el primer renglón del nombre, cuentan a favor del nombre completo. */
    @Test public void nombreCompletoGanaALaLecturaParcial() {
        AnalizadorTarjeta.Estabilizador e = new AnalizadorTarjeta.Estabilizador(3, 6);
        e.agregar(new AnalizadorTarjeta.Lectura("00287", "Ramiro Esteban", 62));
        e.agregar(new AnalizadorTarjeta.Lectura("00287", "Ramiro Esteban", 62));
        AnalizadorTarjeta.Lectura ok = e.agregar(new AnalizadorTarjeta.Lectura("00287", NOMBRE_CRED, 62));
        assertNotNull(ok);
        assertEquals(NOMBRE_CRED, ok.nombre);
        // Dos nombres distintos con los mismos votos: gana el más completo
        AnalizadorTarjeta.Estabilizador e2 = new AnalizadorTarjeta.Estabilizador(2, 6);
        e2.agregar(new AnalizadorTarjeta.Lectura("4471", "Ana Ruiz", 50));
        assertEquals("Ana Ruiz Paez", e2.agregar(new AnalizadorTarjeta.Lectura("4471", "Ana Ruiz Paez", 50)).nombre);
    }
}
