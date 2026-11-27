package mx.com.avba.caseta;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Random;
import java.util.Set;

/**
 * Simulación de muchas credenciales con rayas de la impresora, todas con IDs inventados.
 *
 * Las rayas salen siempre en el mismo lugar de la credencial (el cabezal de la impresora), pero los
 * números no: la letra es proporcional (el «1» es más angosto), así que en cada ID la raya cae en otro
 * lado: entre dos números, pegada a uno o atravesándolo. Aquí se arma el recuadro «ID: 0xxxx» con esa
 * forma, se ponen una o dos rayas en cualquier lugar (también como las de la impresora real: una en el
 * 2.º número y otra a 2.15 números de altura a la derecha) y se generan las lecturas que podría dar el
 * reconocimiento del teléfono: la raya como «|», «I», «l», «1» o espacio, fundida con el número o aparte,
 * el número con la raya encima como «Ø» o «$». Cada lectura va con la caja de cada carácter (como la da
 * el teléfono), con cajas que no dicen nada (todas del mismo ancho) o sin cajas, y con la base del
 * personal vacía, con unos cuantos IDs o con todos seguidos (00001 a 02500).
 *
 * Lo que se exige: el ID sale bien o no sale (null: se vuelve a leer o se escribe). Con la forma de cada
 * carácter salen todas y nunca sale otro ID. Sin la forma: sin base nunca sale otro ID, y con la base a
 * quien está en ella nunca se le confunde con otra persona.
 */
public class RayasSimulacionTest {

    private static final double H = 60;                    // alto de los números en la imagen (px)
    private static final double AVANCE = 0.67 * H, AVANCE_1 = 0.50 * H;
    private static final double GROSOR = 0.045 * H;        // ancho de la raya
    private static final double TOCA = 0.05 * H;           // a menos de esto de un número, la raya lo toca
    private static final double X0 = 2.1 * H;              // donde empieza el número (después de «ID: »)
    private static final double SEPARACION_REAL = 2.15 * H; // entre las dos rayas de la impresora

    /** Cómo vienen las cajas de cada carácter. */
    private enum Cajas { FORMA, IGUALES, SIN }

    private static final class Caracter {
        final char c; final double a, b; final boolean raya;
        Caracter(char c, double a, double b, boolean raya) { this.c = c; this.a = a; this.b = b; this.raya = raya; }
        double centro() { return (a + b) / 2; }
    }

    private static final class Resultado {
        int bien, nulo, mal, malEnBase; final List<String> ejemplosMal = new ArrayList<>();
        int total() { return bien + nulo + mal; }
        @Override public String toString() {
            return String.format(Locale.ROOT, "%d lecturas: %.1f %% bien, %.1f %% sin leer, %d mal (%d de alguien en la base)",
                total(), 100.0 * bien / total(), 100.0 * nulo / total(), mal, malEnBase);
        }
    }

    /** Dónde queda la tinta de cada número (proporcional: el «1» es más angosto y lleva su bandera). */
    private static double[][] tinta(String id) {
        double[][] t = new double[id.length()][2];
        double x = X0;
        for (int k = 0; k < id.length(); k++) {
            boolean uno = id.charAt(k) == '1';
            double av = uno ? AVANCE_1 : AVANCE;
            t[k][0] = x + (uno ? 0.12 : 0.07) * av;
            t[k][1] = x + (uno ? 0.85 : 0.93) * av;
            x += av;
        }
        return t;
    }

    private static char rayaComo(Random rnd) { return "|Il1".charAt(rnd.nextInt(4)); }

    /**
     * Una lectura del recuadro con rayas en «rayas» (x del centro): lo que podría leer el reconocimiento.
     * Regresa los caracteres en orden; un espacio se marca con c = ' '.
     */
    private static List<Caracter> leer(String id, double[] rayas, Random rnd) {
        double[][] t = tinta(id);
        List<Caracter> cs = new ArrayList<>();
        double[] a = new double[id.length()], b = new double[id.length()];
        char[] c = id.toCharArray();
        for (int k = 0; k < id.length(); k++) { a[k] = t[k][0]; b[k] = t[k][1]; }
        for (double xr : rayas) {
            double ra = xr - GROSOR / 2, rb = xr + GROSOR / 2;
            int dentro = -1, pegada = -1;
            for (int k = 0; k < id.length(); k++) {
                if (rb > a[k] && ra < b[k]) dentro = k;
                else if (ra < b[k] + TOCA && rb > a[k] - TOCA) pegada = k;
            }
            int p = rnd.nextInt(100);
            if (dentro >= 0) {
                int k = dentro;
                if (p < 55) {                                  // fundida: se lee el número (con su caja un poco más ancha)
                    a[k] = Math.min(a[k], ra); b[k] = Math.max(b[k], rb);
                    if (c[k] == '0' && p < 6) c[k] = 'Ø';
                    else if (c[k] == '5' && p < 6) c[k] = '$';
                } else {                                       // aparte, del lado de la raya
                    cs.add(new Caracter(rayaComo(rnd), ra, rb, true));
                }
            } else if (pegada >= 0) {
                int k = pegada;
                if (p < 40) { a[k] = Math.min(a[k], ra); b[k] = Math.max(b[k], rb); }
                else cs.add(new Caracter(rayaComo(rnd), ra, rb, true));
            } else {                                           // entre dos números, antes o después
                if (p < 30) continue;                          // no se ve
                cs.add(new Caracter(p < 40 ? ' ' : rayaComo(rnd), ra, rb, true));
            }
        }
        for (int k = 0; k < id.length(); k++) cs.add(new Caracter(c[k], a[k], b[k], false));
        cs.sort((x, y) -> Double.compare(x.centro(), y.centro()));
        return cs;
    }

    /** El renglón «ID: …» como lo entrega el teléfono: texto y, según «modo», la caja de cada carácter. */
    private static AnalizadorTarjeta.Linea renglon(List<Caracter> cs, Cajas modo, Random rnd) {
        StringBuilder t = new StringBuilder("ID: ");
        List<AnalizadorTarjeta.Simbolo> sim = new ArrayList<>();
        int arriba = 800;
        sim.add(new AnalizadorTarjeta.Simbolo('I', 10, arriba, 25, arriba + (int) H));
        sim.add(new AnalizadorTarjeta.Simbolo('D', 32, arriba, 72, arriba + (int) H));
        sim.add(new AnalizadorTarjeta.Simbolo(':', 82, arriba + 15, 92, arriba + (int) H));
        double ini = cs.get(0).a, fin = cs.get(cs.size() - 1).b;
        int visibles = 0;
        for (Caracter x : cs) if (x.c != ' ') visibles++;
        int j = 0;
        double margen = rnd.nextInt(3);                        // el teléfono puede dar cajas un poco más anchas
        for (Caracter x : cs) {
            if (x.c == ' ') { t.append(' '); continue; }
            t.append(x.c);
            double a = x.a - margen, b = x.b + margen;
            if (modo == Cajas.IGUALES) {                       // cajas repartidas por igual: no dicen nada de la forma
                double w = (fin - ini) / visibles;
                a = ini + j * w; b = a + w;
            }
            int alto = x.raya ? (int) (1.8 * H) : (int) H;
            int y0 = x.raya ? arriba - (int) (0.4 * H) : arriba;
            sim.add(new AnalizadorTarjeta.Simbolo(x.c, (int) Math.round(a), y0, (int) Math.round(b), y0 + alto));
            j++;
        }
        return new AnalizadorTarjeta.Linea(t.toString(), arriba, 10, (int) H, (int) Math.round(fin) - 10,
                modo == Cajas.SIN ? null : sim);
    }

    private static String id5(int n) { return String.format(Locale.ROOT, "%05d", n); }

    private static AnalizadorTarjeta.Reglas reglas(Set<String> base) {
        AnalizadorTarjeta.Reglas r = new AnalizadorTarjeta.Reglas();
        r.digitosId = 5;
        r.minDigitos = 5;
        r.ponerIds(base.toArray(new String[0]));
        return r;
    }

    /** IDs inventados: al azar entre 00001 y 02500, y otros con muchos «1» (donde más se confunde la raya). */
    private static List<String> ids(Random rnd, int n) {
        List<String> l = new ArrayList<>();
        String[] unos = { "01111", "01011", "00111", "01101", "01110", "00011", "00101", "01001", "00110", "01211",
                          "02111", "00141", "00231", "01231", "02871", "01287", "00211", "01121", "01181", "02011" };
        for (String u : unos) l.add(u);
        while (l.size() < n) l.add(id5(1 + rnd.nextInt(2500)));
        return l;
    }

    /**
     * Las rayas. «impresora»: como en las credenciales reales, la primera a 0.9 números de altura del inicio del
     * número (± 0.35, de una credencial a otra) y la segunda 2.15 más a la derecha. Si no, en cualquier lugar:
     * dos con esa separación, una sola o dos con otra separación.
     */
    private static double[] rayas(Random rnd, boolean impresora) {
        if (impresora) {
            double x = X0 + (0.9 - 0.35 + rnd.nextDouble() * 0.7) * H;
            return new double[] { x, x + SEPARACION_REAL };
        }
        double x = X0 - 0.6 * H + rnd.nextDouble() * 4.2 * H;
        int p = rnd.nextInt(10);
        if (p < 6) return new double[] { x, x + SEPARACION_REAL };
        if (p < 8) return new double[] { x };
        return new double[] { x, x + (0.8 + rnd.nextDouble() * 2.5) * H };
    }

    /**
     * Lee «lecturas» credenciales al azar. tipoBase: «sin» (base vacía), «unos» (la persona y 300 IDs al azar) o
     * «continua» (todos los IDs de 00001 a 02500, como una plantilla numerada; algunos IDs de la lista quedan fuera).
     */
    private static Resultado correr(Cajas modo, String tipoBase, boolean impresora, int lecturas, long semilla) {
        Random rnd = new Random(semilla);
        List<String> ids = ids(rnd, 400);
        Set<String> base = new HashSet<>();
        if (tipoBase.equals("continua")) for (int k = 1; k <= 2500; k++) base.add(id5(k));
        AnalizadorTarjeta.Reglas r = reglas(base);
        Resultado res = new Resultado();
        for (int i = 0; i < lecturas; i++) {
            String id = ids.get(rnd.nextInt(ids.size()));
            if (tipoBase.equals("unos")) {
                base.clear();
                base.add(id);
                for (int k = 0; k < 300; k++) base.add(id5(1 + rnd.nextInt(99999)));
                r.ponerIds(base.toArray(new String[0]));
            }
            List<Caracter> cs = leer(id, rayas(rnd, impresora), rnd);
            List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
            l.add(renglon(cs, modo, rnd));
            l.add(new AnalizadorTarjeta.Linea("N SS: 12345678901", 1325, 190, 40, 480));
            String c = AnalizadorTarjeta.analizar(l, r).codigo;
            if (c == null) res.nulo++;
            else if (c.equals(id)) res.bien++;
            else {
                res.mal++;
                if (base.contains(id)) res.malEnBase++;
                if (res.ejemplosMal.size() < 8) res.ejemplosMal.add(id + " leído «" + l.get(0).texto + "» → " + c);
            }
        }
        return res;
    }

    private static Resultado ver(String nombre, Resultado r) {
        System.out.println(nombre + ": " + r + (r.ejemplosMal.isEmpty() ? "" : "  " + r.ejemplosMal));
        return r;
    }

    /** Con la caja de cada carácter (como la da el teléfono) la raya se reconoce por su forma: con las rayas de la
     *  impresora o en cualquier lugar, con o sin base, todas salen y nunca sale otro ID. */
    @Test public void conLaFormaDeCadaCaracterNuncaOtroId() {
        for (boolean impresora : new boolean[] { true, false })
            for (String base : new String[] { "sin", "unos", "continua" }) {
                Resultado r = ver("forma, " + (impresora ? "rayas de la impresora" : "rayas en cualquier lugar") + ", base " + base,
                                  correr(Cajas.FORMA, base, impresora, 2500, 11 + base.length() + (impresora ? 100 : 0)));
                assertEquals("otro ID con la forma: " + r.ejemplosMal, 0, r.mal);
                assertTrue("con la forma salen todas: " + r, r.bien >= 0.99 * r.total());
            }
    }

    /** Sin la forma (cajas que no dicen nada, o sin cajas) y sin base: no se adivina; nunca sale otro ID. */
    @Test public void sinLaFormaYSinBaseNoSeAdivina() {
        for (Cajas modo : new Cajas[] { Cajas.IGUALES, Cajas.SIN })
            for (boolean impresora : new boolean[] { true, false }) {
                Resultado r = ver(modo + ", " + (impresora ? "rayas de la impresora" : "rayas en cualquier lugar") + ", sin base",
                                  correr(modo, "sin", impresora, 2500, 7 + modo.ordinal() + (impresora ? 100 : 0)));
                assertEquals("otro ID sin base: " + r.ejemplosMal, 0, r.mal);
                assertTrue("sin la forma y sin base sale la mayoría: " + r, r.bien >= 0.65 * r.total());
            }
    }

    /** Sin la forma, con la base: decide las dudas. A quien está en la base nunca se le confunde con otra persona;
     *  sólo a alguien que todavía no está en la base, muy rara vez, se le puede tomar por uno de la base. */
    @Test public void sinLaFormaDecideLaBase() {
        for (boolean impresora : new boolean[] { true, false })
            for (String base : new String[] { "unos", "continua" }) {
                Resultado r = ver("sin cajas, " + (impresora ? "rayas de la impresora" : "rayas en cualquier lugar") + ", base " + base,
                                  correr(Cajas.SIN, base, impresora, 2500, 5 + base.length() + (impresora ? 100 : 0)));
                assertEquals("otro ID de alguien en la base: " + r.ejemplosMal, 0, r.malEnBase);
                assertTrue("muy rara vez otro ID: " + r, r.mal <= 0.002 * r.total());
                assertTrue("con la base sale casi todo: " + r, r.bien >= (base.equals("unos") ? 0.95 : 0.8) * r.total());
            }
    }
}
