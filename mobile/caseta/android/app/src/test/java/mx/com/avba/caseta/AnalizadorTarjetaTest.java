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
